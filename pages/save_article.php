<?php
include '../config.php';
include '../php/db_config.php';

$session_lifetime = 24 * 60 * 60;

session_start();

if (isset($_SESSION['SESSION_START_TIME']) && (time() - $_SESSION['SESSION_START_TIME'] > $session_lifetime)) {
    session_unset();
    session_destroy();
    header("Location: " . BASE_URL . "pages/admin.php");
    exit;
}

// Check if user is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: " . BASE_URL . "pages/admin.php");
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: add_article.php");
    exit;
}

try {
    $title = $_POST['title'];
    $content = $_POST['content'];
    $type = $_POST['type'];

    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }

    $conn->begin_transaction();

    $stmt = $conn->prepare("INSERT INTO articles (title, content, type) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $title, $content, $type);

    if (!$stmt->execute()) {
        throw new Exception("Error adding article: " . $stmt->error);
    }

    $article_id = $stmt->insert_id;

    if (!empty($_FILES['media']['name'][0])) {
        $upload_dir = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;

        if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
            throw new Exception("Failed to create uploads directory.");
        }

        foreach ($_FILES['media']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['media']['error'][$key] !== UPLOAD_ERR_OK) {
                continue;
            }

            // Validate file type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $tmp_name);
            finfo_close($finfo);

            if (!in_array($mime_type, ['image/jpeg', 'image/png', 'video/mp4'])) {
                throw new Exception("Invalid file type: " . $_FILES['media']['name'][$key]);
            }

            // Validate file size
            $max_file_size = 100 * 1024 * 1024; // 10 MB
            if ($_FILES['media']['size'][$key] > $max_file_size) {
                throw new Exception("File size exceeds the limit: " . $_FILES['media']['name'][$key]);
            }

            $media_ext = pathinfo($_FILES['media']['name'][$key], PATHINFO_EXTENSION);
            $unique_name = uniqid('media_', true) . '.' . $media_ext;
            $media_url = 'uploads/' . $unique_name;
            $full_path = $upload_dir . $unique_name;

            $media_type = strpos($mime_type, 'image') !== false ? 'photo' : 'video';

            if (!move_uploaded_file($tmp_name, $full_path)) {
                throw new Exception("Error uploading file: " . $_FILES['media']['name'][$key]);
            }

            $stmt = $conn->prepare("INSERT INTO media_article (article_id, media_url, media_type) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $article_id, $media_url, $media_type);

            if (!$stmt->execute()) {
                throw new Exception("Error saving media record: " . $stmt->error);
            }
        }
    }

    $conn->commit();

    header("Location: success.php?message=" . urlencode("Article added successfully!"));
    exit;

} catch (Exception $e) {
    if ($conn->connect_error === false) {
        $conn->rollback();
    }

    error_log("Error in save_article.php: " . $e->getMessage());

    header("Location: success.php?error=1&message=" . urlencode($e->getMessage()));
    exit;
}
?>