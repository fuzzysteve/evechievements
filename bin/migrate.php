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

foreach ($files as $file) {
    $version = pathinfo($file, PATHINFO_FILENAME);
    $check = $pdo->prepare("SELECT 1 FROM schema_migrations WHERE version = ? LIMIT 1");
    try {
        $check->execute([$version]);
        if ($check->fetchColumn()) {
            echo "[SKIP] $version
";
            continue;
        }
    } catch (PDOException $e) {
        // schema_migrations table may not exist yet on first run
    }
    echo "[RUN]  $version
";
    $pdo->exec(file_get_contents($file));
    echo "[OK]   $version
";
}
echo "Migrations complete.
";
