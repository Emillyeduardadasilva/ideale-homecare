// A API marca a dose dada como "confirmed"; nas telas de agenda isso significa "Administrado".
// Visitas usam "confirmed" com outro sentido (visita confirmada, ainda não realizada).
export function normalizarItem(item) {
  if (item?.type === 'medication' && item.status === 'confirmed') {
    return { ...item, status: 'administered' }
  }
  return item
}
