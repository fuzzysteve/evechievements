<?php
declare(strict_types=1);
require __DIR__ . "/../vendor/autoload.php";
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . "/..");
$dotenv->safeLoad();

$dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s", $_ENV["DB_HOST"], $_ENV["DB_PORT"], $_ENV["DB_NAME"]);
$pdo = new PDO($dsn, $_ENV["DB_USER"], $_ENV["DB_PASS"], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$migrationDir = __DIR__ . "/../migrations";
$files = glob($migrationDir . "/*.sql");
sort($files);

// Each migration runs in its own transaction and is recorded here once it succeeds, so a file
// doesn't have to insert its own version (older ones still do; ON CONFLICT makes that harmless).
$tracked = fn(): bool => (bool) $pdo->query("SELECT to_regclass('schema_migrations')")->fetchColumn();
$record  = $pdo->prepare("INSERT INTO schema_migrations (version) VALUES (?) ON CONFLICT (version) DO NOTHING");
$applied = $pdo->prepare("SELECT 1 FROM schema_migrations WHERE version = ?");

foreach ($files as $file) {
    $version = pathinfo($file, PATHINFO_FILENAME);
    if ($tracked()) {
        $applied->execute([$version]);
        if ($applied->fetchColumn()) {
            echo "[SKIP] $version\n";
            continue;
        }
    }
    echo "[RUN]  $version\n";
    $pdo->beginTransaction();
    try {
        $pdo->exec(file_get_contents($file));
        $record->execute([$version]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo "[FAIL] $version: {$e->getMessage()}\n";
        exit(1);
    }
    echo "[OK]   $version\n";
}
echo "Migrations complete.\n";
