<?php

require_once('page.php');

new Query($argv)->run();
die();

//////////////////////////////////////////////////////////////////

class Query
{
    static private $commands = array(
                                     'langtemp' => Page::CHECK_FOR_BROKEN_LANGS,
                                     'langsegs' => Page::CHECK_ORDER_OF_LANGS,
                                     'wrongtemp' => Page::CHECK_FOR_DISALLOWED_ITEMS_IN_LANGUAGES,
                                     'wrongheader' => Page::CHECK_FOR_MAINHEADER_LANGS
                                     );

    private $argv;
    private $flags;
    private $bot;

    public $broken_langs = array();
    public $double_langs = array();
    public $unknown_langs = array();
    public $unsorted_words = array();
    public $disallowed_in_langs = array();
    public $mainheader_langs = array();

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
        if (empty($this->argv)) $this->help('xml-file missing');
        $this->filename = array_shift($this->argv);

        $this->flags = 0;
        $this->bot = false;
        $this->cmds = array();
        while (!empty($this->argv))
        {
            $cmd = array_shift($this->argv);

            if (isset(Query::$commands[$cmd]))
            {
                $this->flags |= Query::$commands[$cmd];
                $this->cmds[$cmd] = true;
            }
            else
            {
                switch ($cmd)
                {
                 case 'all':
                    foreach (Query::$commands as $cmd=>$flag)
                    {
                        $this->flags |= $flag;
                        $this->cmds[$cmd] = true;
                    }
                    break;
                 case '-b':
                    $this->bot = true;
                    break;
                 default:
                    $this->help('unknown command: '.$cmd);
                }
            }
        }

        if (empty($this->cmds))
          $this->help('no commands given');
    }

    private function parseXML()
    {
        $doc = new DOMDocument;

        $xml = new XMLReader();
        $xml->open($this->filename);

        while ($xml->read() && $xml->name !== 'page');

        $progress = 0;
        while ($xml->name === 'page')
        {
            $progress++;
            if ($progress%10000==0) fwrite(STDERR, $progress." items\r");

            $node = simplexml_import_dom($doc->importNode($xml->expand(), true));
            $xml->next('page');

            if ($node->ns!='0') continue;

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

        if (!empty($page->double_langs))
          $this->double_langs[$word] = $page->double_langs;
        if (!empty($page->unknown_langs))
          foreach ($page->unknown_langs as $lang)
            $this->unknown_langs[$lang][] = $word;
        if (!empty($page->unsorted_word))
          foreach ($page->unsorted_word as $part=>$_)
            $this->unsorted_words[$part][] = $word;

        if (!empty($page->broken_langs))
          $this->broken_langs[$word] = $page->broken_langs;

        if (!empty($page->mainheader_langs))
          $this->mainheader_langs[$word] = $page->mainheader_langs;

        if (!empty($page->disallowed_in_langs))
          foreach ($page->disallowed_in_langs as $type=>$data)
            $this->disallowed_in_langs[$type][$word] = $data;
    }

    private function createOutput()
    {
        foreach ($this->cmds as $name=>$_)
          $this->{'createOutput'.ucfirst($name).($this->bot?'Bot':'')}();
    }

    private function createOutputLangsegs()
    {
        echo PHP_EOL.'== Άγνωστες γλώσσες =='.PHP_EOL;
        foreach ($this->unknown_langs as $ln => $words)
          echo '* '.$ln.' [['.implode(']], [[',$words).']]'.PHP_EOL;

        echo PHP_EOL.'== Γλωσσικά τμήματα που δεν έχουν ταξινομηθεί =='.PHP_EOL;
        foreach ($this->unsorted_words as $type => $words)
        {
            echo '* '.$type.PHP_EOL;
            foreach ($words as $word)
              echo '** [['.$word.']]'.PHP_EOL;
        }

        echo PHP_EOL.'== Γλωσσικά τμήματα που εμφανίζονται δύο φορές =='.PHP_EOL;
        foreach ($this->double_langs as $word => $langs)
          echo '* [['.$word.']] ('.implode(', ',array_keys($langs)).')'.PHP_EOL;
    }

    private function createOutputLangsegsBot()
    {
        foreach ($this->unknown_langs as $ln => $words)
          echo 'ADDLANG '.$ln.PHP_EOL;

        foreach ($this->unsorted_words as $type => $words)
          foreach ($words as $word)
            echo 'SORTLANGS '.$word.PHP_EOL;

        foreach ($this->double_langs as $word => $langs)
          echo 'MERGELANGS '.$word.PHP_EOL;
    }

    private function createOutputLangtemp()
    {
        echo PHP_EOL.'== Προτύπων γλώσσας που βρίσκονται σε λάθος θέση =='.PHP_EOL;
        $used = array();
        foreach ($this->broken_langs as $word=>$tmp)
          foreach ($tmp as list($part,$lang))
          {
              echo '* [['.$word.']]: '.($part==''?'εισαγωγή':$part).' &rarr; '.$lang;
              echo isset($used[$word])?' (διπλός)'.PHP_EOL:PHP_EOL;
              $used[$word] = true;
          }
    }

    private function createOutputLangtempBot()
    {
        foreach ($this->broken_langs as $word=>$tmp)
          echo 'FIXLANG '.$word.PHP_EOL;
    }

    private function createOutputWrongheader()
    {
        echo PHP_EOL.'== Στοιχεία κεφαλίδας που δεν είναι γλωσσικά πρότυπα =='.PHP_EOL;
        foreach ($this->mainheader_langs as $word => $langs)
          echo '* [['.$word.']] ('.implode(', ',$langs).')'.PHP_EOL;
    }

    private function createOutputWrongheaderBot()
    {
        foreach ($this->mainheader_langs as $word => $langs)
          echo 'FIXHEADER '.$word.PHP_EOL;
    }

    private function createOutputWrongtemp()
    {
        echo PHP_EOL.'== Πρότυπα που δεν έχουν θέση στις ενότητες γλωσσών =='.PHP_EOL;
        foreach ($this->disallowed_in_langs as $type => $tmp)
        {
            echo '* '.$type.PHP_EOL;
            foreach ($tmp as $word => $langs)
              echo '** [['.$word.']] ('.implode(', ',$langs).')'.PHP_EOL;
        }
    }

    private function createOutputWrongtempBot()
    {
        $also = array();
        $kat = array();
        $ell = array();
        foreach ($this->disallowed_in_langs as $type => $tmp)
          foreach ($tmp as $word => $langs)
            switch ($type)
            {
             case 'δείτε': case 'also':
                $also[$word] = true;
                break;
             case 'Κατηγορία':
                $kat[$word] = true;
                break;
             case 'κλείδα-ελλ':
                $ell[$word] = true;
                break;
            }

        foreach ($also as $word=>$_)
          echo 'FIXALSO '.$word.PHP_EOL;

        foreach ($kat as $word=>$_)
          echo 'FIXKAT '.$word.PHP_EOL;

        foreach ($ell as $word=>$_)
          echo 'FIXELL '.$word.PHP_EOL;
    }

    private function help($error=false)
    {
        if ($error!==false)
          fwrite(STDERR,'Error: '.$error.PHP_EOL.PHP_EOL);

        fwrite(STDERR,<<<EOF
SYNOPSIS
        php query.php <xml-file> <options|commands...>

OPTIONS
        -b
            create output which can be used as input for the bot

COMMANDS
        all
            Execute all commands.

        langsegs
            Search for language segments that have not been sorted
            correctly, for duplicate language segments, and for
            language segments with unknown languages.

        langtemp
            Search for language templates that are in the wrong place.

        wrongheader
            Search for first or second level header elements that are
            not simple language templates.

        wrongtemp
            Search for templates that have no place in language sections.

EOF
        );
        die();
    }
}
?>
