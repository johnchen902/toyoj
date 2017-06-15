<?php
namespace Toyoj;
class StyleSheets {
    const PREFERRED_KEY = "preferred";
    private $segment;
    private $sheets;
    public function __construct($container, array $sheets) {
        if(!$sheets)
            throw new \InvalidArgumentException("\$sheets is empty");
        $this->segment = $container->session->getSegment("Toyoj\StyleSheets");
        $this->sheets = $sheets;
    }
    public function getPreferredTitle() {
        $title = $this->segment->get(self::PREFERRED_KEY);
        if(!is_null($title) && isset($this->sheets[$title]))
            return $title;
        foreach($this->sheets as $title => $path)
            return $title;
    }
    public function setPreferredTitle($title) {
        if(!is_null($title) && !isset($this->sheets[$title]))
            throw new \InvalidArgumentException("no stylesheets with such title");
        $this->segment->set(self::PREFERRED_KEY, $title);
    }
    public function listAll() {
        $result = [];
        $preferred = $this->getPreferredTitle();
        foreach($this->sheets as $title => $path) {
            $result[] = [
                "title" => $title,
                "path" => $path,
                "preferred" => $preferred == $title,
            ];
        }
        return $result;
    }
}
