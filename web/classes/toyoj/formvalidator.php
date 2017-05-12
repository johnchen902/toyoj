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

    public function validateSubmission($lang, $code) {
        $e = array();
        $this->validateString($e, $lang, "Language", 1, 32);
        $this->validateString($e, $code, "Code", 1, 65536);
        return $e;
    }
    public function validateProblem($title, $statement) {
        $e = array();
        $this->validateString($e, $title, "Title", 1, 128);
        $this->validateString($e, $statement, "Statement", 1, 65536);
        return $e;
    }
    public function validateSubtask($score, $testcaseids, $alltestcaseids) {
        $e = array();
        if($score < 1)
            $e[] = "Score is less than 1";
        if(is_null($testcaseids))
            $e[] = "No test cases are selected";
        else if(!is_array($testcaseids))
            $e[] = "\$testcaseids is not an array";
        if(!is_array($alltestcaseids))
            $e[] = "\$alltestcaseids is not an array";
        return $e;
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
