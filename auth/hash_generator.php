<?php
// The password you want to use for Admin
$password = 'fishdrying'; 

// Generate the secure hash
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

echo "Your Hashed Password for 'fishdrying' is: <br><strong>" . $hashed_password . "</strong>";
?>