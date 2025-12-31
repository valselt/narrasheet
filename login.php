<?php
$narrasheet_login_url = "https://valseltid.ivanaldorino.web.id/login";

$my_callback_url = "https://narrasheet.ivanaldorino.web.id/auth_callback.php";

$encoded_url = base64_encode($my_callback_url);

header("Location: " . $narrasheet_login_url . "?redirect_to=" . urlencode($encoded_url));
exit();
?>