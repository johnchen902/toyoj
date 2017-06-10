<?php
namespace Toyoj;
class FormValidator {
    private function validateString(array &$e, string $str, string $name, int $minl, int $maxl) {
        $len = strlen($str);
        if($len < $minl) {
            if($minl == 1)
                $e[] = "$name is empty.";
            else
                $e[] = "$name is shorter than $minl characters.";
        }
        if($len > $maxl) {
            $e[] = "$name is longer than $maxl characters.";
        }
    }

    public function validateTestCase($time, $mem, $checker, $input, $output) {
        $e = array();
        if($time < 100 || $time > 15000)
            $e[] = "Time limit is out of range [100 ms, 15000 ms]";
        if($mem < 8192 || $mem > 1048576)
            $e[] = "Memory limit is out of range [8192 KiB, 1048576 KiB]";
        $this->validateString($e, $checker, "Checker", 1, 32);
        $this->validateString($e, $input, "Input", 1, 16 << 20);
        $this->validateString($e, $output, "Output", 1, 16 << 20);
        return $e;
    }
}
?>
