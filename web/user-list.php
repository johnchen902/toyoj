<?php
require_once("include/site.php");
$site = new Toyoj();
$users = $site->pg_query("SELECT uid, username FROM users ORDER BY uid");

$site->write_header();
?>
  <table>
    <tr><th>UID</th><th>Name</th></tr>
<?php
while($user = pg_fetch_assoc($users)) {
    $user["username"] = htmlentities($user["username"]);
?>
    <tr><td><?=$user["uid"]?></td><td><?=$user["username"]?></td></tr>
<?php
}
?>
  </table>
<?php
$site->write_footer();
?>
