<?php
session_start();
$configFilePath = '../conn.php';
if (!file_exists($configFilePath)) {
    echo json_encode(['success' => false, 'message' => 'Fichier de configuration introuvable.']);
    exit();
}
require_once '../connexion_bdd.php';
if (!isset($_SESSION['user_token']) || !isset($_SESSION['user_email'])) {
    echo json_encode(['success' => false, 'message' => 'Accès refusé. Veuillez vous connecter.']);
    exit();
}

function getCurrentVersion() {
    return trim(file_get_contents('version.txt'));
}

function getLatestVersion() {
    $url = 'https://raw.githubusercontent.com/CentralCorp/CentralCorp-Panel/main/update/version.txt';
    $opts = [
        "http" => [
            "method" => "GET",
            "header" => "Cache-Control: no-cache, no-store, must-revalidate\r\n"
        ]
    ];
    $context = stream_context_create($opts);
    return trim(file_get_contents($url, false, $context));
}

function isNewVersionAvailable($currentVersion, $latestVersion) {
    return version_compare($currentVersion, $latestVersion, '<');
}

function updateFiles() {
    $zipFile = 'update.zip';
    $url = 'https://github.com/CentralCorp/CentralCorp-Panel/archive/refs/heads/main.zip';

    file_put_contents($zipFile, fopen($url, 'r'));

    $zip = new ZipArchive;
    if ($zip->open($zipFile) === TRUE) {
        $extractPath = './temp-update';
        mkdir($extractPath);

        $zip->extractTo($extractPath);
        $zip->close();
        unlink($zipFile);

        $innerFolder = $extractPath . '/CentralCorp-Panel-main';
        if (is_dir($innerFolder)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($innerFolder, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($files as $file) {
                $destination = str_replace($innerFolder, '..', $file);

                if ($file->isDir()) {
                    mkdir($destination);
                } else {
                    rename($file, $destination);
                }
            }
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($innerFolder, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getRealPath());
                } else {
                    unlink($file->getRealPath());
                }
            }

            rmdir($innerFolder);
        }
        rmdir($extractPath);

        return true;
    } else {
        return false;
    }
}
function updateDatabase($pdo) {
    $sqlFilePath = '../utils/panel.sql';
    if (!file_exists($sqlFilePath)) {
        return ['success' => false, 'message' => "Fichier panel.sql introuvable."];
    }

    $sqlContent = file_get_contents($sqlFilePath);
    $tableSegments = explode('CREATE TABLE', $sqlContent);
    array_shift($tableSegments);

    $existingTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tableSegments as $segment) {
        $segment = 'CREATE TABLE ' . $segment;
        preg_match('/`(\w+)`/', $segment, $tableMatch);
        if (isset($tableMatch[1])) {
            $tableName = $tableMatch[1];

            // Ensure table exists
            if (!in_array($tableName, $existingTables)) {
                if ($pdo->exec($segment) === false) {
                    throw new Exception("Erreur lors de la création de la table '$tableName'.");
                }
            }

            // Update table collation to utf8mb4_general_ci
            $pdo->exec("ALTER TABLE `$tableName` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

            // Check and update column collations
            $result = $pdo->query("SHOW FULL COLUMNS FROM `$tableName`");
            $existingColumns = [];
            while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                $existingColumns[$row['Field']] = $row['Collation'];
            }

            preg_match_all('/`(\w+)` (\w+\([\d,]+\)|\w+(\(\d+\))?)/', $segment, $matches);
            $newColumns = array_combine($matches[1], $matches[2]);

            foreach ($newColumns as $column => $type) {
                if (isset($existingColumns[$column]) && $existingColumns[$column] !== 'utf8mb4_general_ci') {
                    $alterQuery = "ALTER TABLE `$tableName` MODIFY `$column` $type CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
                    if ($pdo->exec($alterQuery) === false) {
                        return ['success' => false, 'message' => "Erreur lors de la modification de la collation de la colonne '$column' dans la table '$tableName'."];
                    }
                }
            }
        } else {
            throw new Exception("Impossible d'extraire le nom de la table pour le segment suivant : \n$segment\n");
        }
    }

    return ['success' => true, 'message' => "Base de données mise à jour avec succès."];
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_button'])) {
    $currentVersion = getCurrentVersion();
    $latestVersion = getLatestVersion();

    if (isNewVersionAvailable($currentVersion, $latestVersion)) {
        if (updateFiles()) {
            $dbUpdateResult = updateDatabase($pdo);
            if ($dbUpdateResult['success']) {
                file_put_contents('version.txt', $latestVersion);
                echo json_encode(['success' => true, 'message' => "Mise à jour terminée avec succès à la version $latestVersion."]);
            } else {
                echo json_encode($dbUpdateResult);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Échec de la mise à jour des fichiers.']);
        }
    } else {
        echo json_encode(['success' => true, 'message' => 'Aucune mise à jour disponible.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Requête non valide.']);
}
?>
