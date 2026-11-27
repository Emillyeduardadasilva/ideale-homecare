<?php

// A montagem /source é somente leitura. O preparo ocorre no contêiner descartável.
function copyTree(string $source, string $destination): void
{
    if (! is_dir($source)) {
        throw new RuntimeException('Diretório de origem ausente: '.$source);
    }
    if (! is_dir($destination)) {
        mkdir($destination, 0755, true);
    }
    foreach (new FilesystemIterator($source) as $entry) {
        $target = $destination.'/'.$entry->getFilename();
        if ($entry->isLink()) {
            throw new RuntimeException('Link inesperado no pacote: '.$entry->getPathname());
        }
        if ($entry->isDir()) {
            copyTree($entry->getPathname(), $target);
        } elseif (! copy($entry->getPathname(), $target)) {
            throw new RuntimeException('Falha ao copiar arquivo.');
        }
    }
}

$home = '/build/home';
$backend = $home.'/ideale-backend';
$public = $home.'/public_html';
mkdir($backend, 0755, true);

// A lista explícita impede copiar .env local, banco, logs e dados de usuários.
foreach (['app', 'config', 'lang', 'routes', 'public'] as $directory) {
    copyTree('/source/backend/'.$directory, $backend.'/'.$directory);
}
copyTree('/source/backend/database/migrations', $backend.'/database/migrations');
foreach (['bootstrap/cache', 'docs', 'resources/views', 'storage/app/private', 'storage/app/public',
          'storage/framework/cache/data', 'storage/framework/sessions',
          'storage/framework/views', 'storage/logs'] as $directory) {
    mkdir($backend.'/'.$directory, 0755, true);
}
foreach (['artisan', 'composer.json', 'composer.lock', 'hostgator-cron.php',
          'bootstrap/app.php', 'bootstrap/providers.php', 'docs/openapi.json'] as $file) {
    copy('/source/backend/'.$file, $backend.'/'.$file);
}

chdir($backend);
passthru('composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader', $status);
if ($status !== 0) {
    exit($status);
}
copy('/source/backend/.env.production.example', $backend.'/.env');
copy('/source/backend/.env.production.example', $backend.'/.env.production.example');

copyTree('/source/frontend/dist', $public);
copyTree('/source/deploy/hostgator/public', $public);
copy('/source/deploy/hostgator/README.md', $home.'/LEIA-ME.md');

// Barras e permissões UNIX são preservadas para a extração no Linux do cPanel.
$zip = new ZipArchive;
$filename = '/output/ideale-homecare-hostgator.zip';
$temporary = $filename.'.tmp';
if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Não foi possível criar o ZIP.');
}
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($home, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($iterator as $file) {
    $relative = substr($file->getPathname(), strlen($home) + 1);
    if ($file->isDir()) {
        $relative .= '/';
        $zip->addEmptyDir($relative);
        $mode = 040755;
    } else {
        $zip->addFile($file->getPathname(), $relative);
        $mode = $relative === 'ideale-backend/.env' ? 0100600 : 0100644;
    }
    $zip->setExternalAttributesName($relative, ZipArchive::OPSYS_UNIX, $mode << 16);
}
if (! $zip->close() || ! rename($temporary, $filename)) {
    throw new RuntimeException('Falha ao finalizar o ZIP.');
}
echo "Pacote criado em ".$filename.PHP_EOL;
