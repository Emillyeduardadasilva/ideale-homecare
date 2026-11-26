import api from './api'

const key = import.meta.env.VITE_REVERB_APP_KEY
const host = import.meta.env.VITE_REVERB_HOST || '127.0.0.1'
const port = import.meta.env.VITE_REVERB_PORT || '8080'
const scheme = import.meta.env.VITE_REVERB_SCHEME || 'http'

export function listenForCareReminders(userId, onReminder) {
  if (!key || !host || !userId || !('WebSocket' in window)) return () => {}

  let stopped = false
  let socket
  let reconnectTimer
  let attempts = 0
  const channel = `private-care.users.${userId}`
  const protocol = scheme === 'https' ? 'wss' : 'ws'
  const url = `${protocol}://${host}:${port}/app/${encodeURIComponent(key)}?protocol=7&client=js&version=8.4.0&flash=false`

  const connect = () => {
    if (stopped) return
    socket = new WebSocket(url)

    socket.addEventListener('message', async message => {
      let frame
      try {
        frame = JSON.parse(message.data)
      } catch {
        return
      }

      if (frame.event === 'pusher:ping') {
        socket.send(JSON.stringify({ event: 'pusher:pong', data: {} }))
        return
      }

      if (frame.event === 'pusher:connection_established') {
        try {
          const connection = typeof frame.data === 'string' ? JSON.parse(frame.data) : frame.data
          const response = await api.post('/broadcasting/auth', {
            socket_id: connection.socket_id,
            channel_name: channel
          })
          if (socket.readyState !== WebSocket.OPEN) return
          socket.send(JSON.stringify({
            event: 'pusher:subscribe',
            data: { auth: response.data.auth, channel }
          }))
        } catch {
          socket.close()
        }
        return
      }

      if (frame.event === 'care.reminder.available' && frame.channel === channel) {
        const data = typeof frame.data === 'string' ? JSON.parse(frame.data) : frame.data
        if (data?.notification_id) onReminder(data.notification_id)
      }
    })

    socket.addEventListener('open', () => { attempts = 0 })
    socket.addEventListener('error', () => socket.close())
    socket.addEventListener('close', () => {
      if (stopped) return
      attempts += 1
      const delay = Math.min(1000 * (2 ** Math.min(attempts - 1, 5)), 30000)
      reconnectTimer = window.setTimeout(connect, delay)
    })
  }

  connect()
  return () => {
    stopped = true
    window.clearTimeout(reconnectTimer)
    socket?.close()
  }
}
