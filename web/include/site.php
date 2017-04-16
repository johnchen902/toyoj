<?php
class Toyoj {
    public function pg_connect() {
        $conn = pg_connect("dbname=toyoj user=toyojweb");
        if(!$conn) {
            http_response_code(500);
            die("Cannot connect to PostgreSQL.");
        }
        return $conn;
    }
    public function pg_query($query) {
        $conn = $this->pg_connect();
        $result = pg_query($query);
        if(!$result) {
            http_response_code(500);
            die(pg_last_error());
        }
    }

    public function get_title() {
        return "Toy Online Judge";
    }
    public function get_styles() {
        return [
            "nav { margin-bottom: 1em; }",
            "a, a:visited { color: inherit; }",
            ".message { margin: 1em auto; padding: 1em; border: thin solid; }",
            "fieldset { border: none; }",
            "textarea { width: 100%; box-sizing: border-box; }",
        ];
    }
    public function get_h1() {
        return "Toy Online Judge";
    }
    public function get_navs() {
        return [
            ["href" => ".", "text" => "Index"],
            ["href" => "problem-list.php", "text" => "Problems"],
            ["href" => "submission-list.php", "text" => "Submissions"],
            ["href" => "user-list.php", "text" => "Users"],
            ["href" => "signin.php", "text" => "Sign in"],
            ["href" => "signup.php", "text" => "Sign up"],
            ["href" => "signout.php", "text" => "Sign out"],
        ];
    }
    public function get_messages() {
        return [
            ["type" => "ok", "text" => "Test: Hello!"],
            ["type" => "error", "text" => "Test: Under construction..."],
        ];
    }

    public function write_header() {
?>
<!DOCTYPE html>
<html>
<head>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=$this->get_title()?></title>
  <style>
<?php foreach($this->get_styles() as $style) {?>
    <?=$style . "\n"?>
<?php } ?>
  </style>
</head>
<body>
  <h1><?=$this->get_h1()?></h1>
  <nav>
<?php foreach($this->get_navs() as $nav) {?>
    <a href="<?=$nav["href"]?>"><?=$nav["text"]?></a>
<?php } ?>
  </nav>
<?php foreach($this->get_messages() as $msg) {?>
  <div class="message message-<?=$msg["type"]?>">
    <?=$msg["text"] . "\n"?>
  </div>
<?php } ?>
<!-- write_header() end -->
<?php
    }

    public function write_footer() {
?>
<!-- write_footer() begin -->
</body>
</html>
<?php
    }
}
?>
