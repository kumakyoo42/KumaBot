<?php

require_once('page.php');

new Query($argv)->run();
die();

//////////////////////////////////////////////////////////////////

class Query
{
    private $argv;
    private $flags;
    private $bot;

    public $broken_langs = array();
    public $double_langs = array();
    public $unknown_langs = array();
    public $unsorted_words = array();
    public $disallowed_in_langs = array();

    public function __construct($argv)
    {
        $this->argv = $argv;
    }

    public function run()
    {
        $this->parseArguments();
        $this->parseXML();
        $this->createOutput();
    }

    //////////////////////////////////////////////////////////////////

    private function parseArguments()
    {
        array_shift($this->argv);
        if (empty($this->argv)) $this->help('No file');
        $this->filename = array_shift($this->argv);

        $this->flags = 0;
        $this->bot = false;
        $this->cmds = array();
        while (!empty($this->argv))
        {
            $cmd = array_shift($this->argv);

            switch ($cmd)
            {
             case 'broken':
                $this->flags |= Page::CHECK_FOR_BROKEN_LANGS;
                $this->cmds[$cmd] = true;
                break;
             case 'order':
                $this->flags |= Page::CHECK_ORDER_OF_LANGS;
                $this->cmds[$cmd] = true;
                break;
             case 'dislang':
                $this->flags |= Page::CHECK_FOR_DISALLOWED_ITEMS_IN_LANGUAGES;
                $this->cmds[$cmd] = true;
                break;
             case '-b':
                $this->bot = true;
                break;
             default:
                $this->help('Unknown command: '.$cmd);
            }
        }

        if (empty($this->cmds))
          $this->help('No task');

        if ($this->bot and isset($this->cmds['broken']))
          $this->help('broken cannot be fixed automatically');
        if ($this->bot and isset($this->cmds['dislang']))
          $this->help('dislang cannot be fixed automatically');
    }

    private function parseXML()
    {
        $doc = new DOMDocument;

        $xml = new XMLReader();
        $xml->open($this->filename);

        while ($xml->read() && $xml->name !== 'page');

        while ($xml->name === 'page')
        {
            $node = simplexml_import_dom($doc->importNode($xml->expand(), true));
            $xml->next('page');

            if ($node->ns!="0") continue;

            $word = strval($node->title);
            $text = strval($node->revision->text);

            $page = new Page($word, $text);
            $page->setFlags($this->flags);
            $page->dissect();
            if (!$page->isValid()) continue;

            $page->analyze();

            $this->saveResults($word,$page);
        }
        $xml->close();
    }

    private function saveResults($word,$page)
    {
        if (!empty($page->broken_langs))
          $this->broken_langs[$word] = $page->broken_langs;
        if (!empty($page->double_langs))
          $this->double_langs[$word] = $page->double_langs;
        if (!empty($page->unknown_langs))
          foreach ($page->unknown_langs as $lang)
            $this->unknown_langs[$lang][] = $word;
        if (!empty($page->unsorted_word))
          foreach ($page->unsorted_word as $part=>$_)
            $this->unsorted_words[$part][] = $word;
        if (!empty($page->disallowed_in_langs))
          $this->disallowed_in_langs[$word] = $page->disallowed_in_langs;
    }

    private function createOutput()
    {
        if (isset($this->cmds['broken']))
        {
            echo PHP_EOL.'== σελίδες με ελαττωματικό στοιχείο γλώσσας =='.PHP_EOL;
            $used = array();
            foreach ($this->broken_langs as $word=>$tmp)
              foreach ($tmp as list($part,$lang))
              {
                  echo '* [['.$word.']]: '.($part==''?'εισαγωγή':$part).' &rarr; '.$lang;
                  if (isset($used[$word]))
                    echo ' (διπλός)';
                  echo PHP_EOL;
                  $used[$word] = true;
              }
        }

        if (isset($this->cmds['order']))
        {
            if (!$this->bot)
            {
                echo PHP_EOL.'== άγνωστη γλώσσα =='.PHP_EOL;
                foreach ($this->unknown_langs as $ln => $words)
                  echo '* '.$ln.' [['.implode(']], [[',$words).']]'.PHP_EOL;
            }

            if (!$this->bot)
              echo PHP_EOL.'== λέξεις χωρίς σειρά =='.PHP_EOL;
            foreach ($this->unsorted_words as $type => $words)
            {
                if (!$this->bot)
                  echo '* '.$type.PHP_EOL;
                foreach ($words as $word)
                {
                    if ($this->bot)
                      echo 'SORT '.$word.PHP_EOL;
                    else
                      echo '** [['.$word.']]'.PHP_EOL;
                }
            }

            if (!$this->bot)
              echo PHP_EOL.'== διπλή γλώσσα =='.PHP_EOL;
            foreach ($this->double_langs as $word => $langs)
            {
                if ($this->bot)
                  echo 'MERGE '.$word.PHP_EOL;
                else
                  echo '* [['.$word.']] ('.implode(', ',array_keys($langs)).')'.PHP_EOL;
            }
        }

        if (isset($this->cmds['dislang']))
        {
            echo PHP_EOL.'== γλώσσες που περιέχουν ένα απαγορευμένο στοιχείο =='.PHP_EOL;
            foreach ($this->disallowed_in_langs as $word => $langs)
              echo '* [['.$word.']] ('.implode(', ',$langs).')'.PHP_EOL;
        }
    }

    private function help($error=false)
    {
        if ($error!==false) fwrite(STDERR,'Error: '.$error.PHP_EOL.PHP_EOL);

        fwrite(STDERR,'Usage:'.PHP_EOL);
        fwrite(STDERR,'php check.php <xml-file> <commands...>'.PHP_EOL);
        fwrite(STDERR,'commands are:'.PHP_EOL);
        fwrite(STDERR,'  broken (identify broken language links)'.PHP_EOL);
        fwrite(STDERR,'  order (identify language parts in wrong order)'.PHP_EOL);
        fwrite(STDERR,'  dislang (identify invalid objects in language parts)'.PHP_EOL);

        die();
    }
}
?>
