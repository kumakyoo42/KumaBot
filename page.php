<?php

const GREEK         = 100;
const INTERNATIONAL = 200;
const OTHER         = 300;

$order = array(GREEK =>
               "el", "cpg", "grk-ita", "el-crt", "el-cyp", "grk-mar", "pnt", "tsd",
               "gkm", "xmk", "grc", "gmy", "grk-pro",

               INTERNATIONAL =>
               "mul", "uni",

               OTHER =>
               "en", "enm", "xno", "ang", "az", "arz", "ay", "ht", "sq", "sqj-pro",
               "alq", "gsw", "ab", "am", "chm", "ar", "an", "arc", "hy", "xcl",
               "egy", "roa-rup", "as", "ast", "aa", "af", "wa", "eu", "bar", "ve",
               "vec", "vep", "vi", "my", "vo", "bs", "bg", "br", "gl", "fr", "fro",
               "frm", "frp", "de", "gem-pro", "gmh", "goh", "pdc", "pfl", "ka",
               "sah", "yi", "yo", "gal", "gn", "gu", "aaa", "got", "war", "wo", "kl",
               "da", "mlq", "he", "hbo", "lad", "elx", "ext", "ee", "et", "eo",
               "ett", "diq", "zea", "zu", "bo", "jv", "ja", "ig", "ilo", "ine",
               "ine-pro", "id", "iu", "ia", "ie", "io", "ga", "sga", "is", "es",
               "osp", "it", "itc-pro", "kab", "kk", "kn", "zh-yue", "pam", "krc",
               "ks", "csb", "ca", "nds", "nds-nl", "qu", "zh", "gan", "wuu",
               "zh-min-nan", "rw", "ky", "kbd", "ady", "rn", "ksh", "kg", "cop",
               "ko", "kw", "co", "xh", "avk", "ku", "ckb", "cr", "crh", "hr", "acy",
               "lld", "lkt", "lo", "smi", "smn", "se", "la", "lv", "be", "liv",
               "lij", "lt", "li", "ln", "jbo", "lmo", "lb", "xld", "yua", "ml", "ms",
               "mg", "mt", "gv", "mnc", "mi", "arn", "mr", "mwl", "mn", "mdf", "mo",
               "bm", "ba", "bem", "bn", "bi", "bh", "nv", "nah", "nci", "nhn", "na",
               "nap", "new", "la-new", "ne", "nov", "no", "nn", "nb", "non", "nrm",
               "dv", "nl", "dum", "oc", "pro", "or", "os", "oj", "cy", "hu", "uga",
               "uz", "ug", "uk", "udm", "ur", "pi", "pa", "pnb", "pap", "ps", "fa",
               "peo", "pms", "pl", "pt", "ine-bsl-pro", "dra-pro", "sla-pro", "rm",
               "rap", "rom", "roa", "rue", "ro", "ru", "sm", "bat-smg", "sa", "sc",
               "ceb", "see", "sr", "sh", "sei", "scn", "si", "sco", "cu", "orv",
               "gd", "mk", "sk", "sl", "so", "sn", "wen", "hsb", "dsb", "nso", "st",
               "ss", "sw", "sv", "su", "srn", "syc", "tnq", "th", "ty", "ta",
               "roa-tara", "tt", "tg", "te", "tet", "jam", "dz", "tpi", "to", "tpw",
               "tr", "ota", "otk", "trk-pro", "tk", "ch", "chy", "chr", "ce", "cs",
               "ts", "tn", "za", "cv", "fo", "tl", "bcl", "fi", "fj", "hif", "vls",
               "stq", "fy", "fur", "haw", "hak", "ha", "hz", "hit", "hi", "km"
               );

Page::setOrder(array_flip($order));
unset($order);

class Page
{
    final const CHECK_FOR_BROKEN_LANGS = 1;
    final const CHECK_ORDER_OF_LANGS   = 2;

    static private $order;

    static public function setOrder($order)
    {
        self::$order = $order;
    }

    //////////////////////////////////////////////////////////////////

    private $is_valid = false;
    private $flags = 0;

    private $word;
    private $text;
    private $insert;
    private $prefix;
    private $langs;

    public $broken_langs = array();
    public $double_langs = array();
    public $unknown_langs = array();
    public $unsorted_word = array();

    public function __construct($word, $text)
    {
        $this->word = $word;
        $this->text = $text;
    }

    public function isValid()
    {
        return $this->is_valid;
    }

    public function addFlag($flag)
    {
        $this->flags |= $flag;
    }

    public function setFlags($flags)
    {
        $this->flags = $flags;
    }

    //////////////////////////////////////////////////////////////////

    public function dissect()
    {
        $this->is_valid = true;
        if ($this->isGeneralPage())
        {
            $this->is_valid = false;
            return;
        }

        $this->removeNoWikiAndComments();

        if ($this->isRedirect())
        {
            $this->is_valid = false;
            return;
        }

        $this->splitIntoLanguageParts();
    }

    public function join()
    {
        $this->joinLanguageParts();

        $this->addNoWikiAndComments();
        return $this->text;
    }

    public function sortLanguages()
    {
        usort($this->langs, array($this, 'cmpLanguage'));
        $last = array_key_last($this->langs);
        foreach ($this->langs as $k=>$lang)
        {
            $text = trim($lang[1]);
            preg_match('/((\s|-)*)$/',$text,$erg);
            $text = substr($text,0,strlen($text)-strlen($erg[1]));
            if ($k!=$last)
              $text .= PHP_EOL.PHP_EOL.PHP_EOL.'----'.PHP_EOL.PHP_EOL;
            $this->langs[$k] = array($lang[0],$text);
        }
    }

    private function cmpLanguage($a, $b)
    {
        return self::$order[$a[0]]-self::$order[$b[0]];
    }

    //////////////////////////////////////////////////////////////////

    private function isGeneralPage()
    {
        if (str_starts_with($this->word,'Κύρια Σελίδα')) return true;
        if (str_starts_with($this->word,'Πύλη:')) return true;
        return false;
    }

    private function isRedirect()
    {
        if (str_starts_with($this->text,'#REDIRECT')) return true;
        if (str_starts_with($this->text,'#ΑΝΑΚΑΤΕΥΘΥΝΣΗ')) return true;
        if (str_starts_with($this->text,'#redirect')) return true;
        if (str_starts_with($this->text,'#ανακατευθυνση')) return true;
        return false;
    }

    private function removeNoWikiAndComments()
    {
        $parts = preg_split('|(<nowiki>.*</nowiki>)|Usi',$this->text,-1,PREG_SPLIT_DELIM_CAPTURE);
        for ($i=0;$i<count($parts);$i+=2)
          $parts[$i] = preg_split('|(<!--.*-->)|Us',$parts[$i],-1,PREG_SPLIT_DELIM_CAPTURE);

        $nr = 0;

        $this->insert = array();
        $used = array();
        for ($i=0;$i<count($parts);$i++)
        {
            if ($i%2==0)
            {
                for ($j=0;$j<count($parts[$i]);$j++)
                {
                    if ($j%2==0)
                      $used[] = $parts[$i][$j];
                    else
                    {
                        $this->insert[$nr] = $parts[$i][$j];
                        $used[] = chr(1).$nr.chr(2);
                        $nr++;
                    }
                }
            }
            else
            {
                $this->insert[$nr] = $parts[$i];
                $used[] = chr(1).$nr.chr(2);
                $nr++;
            }
        }
        $this->text = implode('', $used);
    }

    private function addNoWikiAndComments()
    {
        foreach ($this->insert as $marker=>$replace)
          $this->text = str_replace(chr(1).$marker.chr(2),$replace,$this->text);
    }

    private function splitIntoLanguageParts()
    {
        $parts = preg_split('/^==\s*{{-([a-z-]+)-}}\s*==\s*$/Um',$this->text,-1,PREG_SPLIT_DELIM_CAPTURE);
        $this->prefix = $parts[0];
        $this->langs = array();
        for ($i=1;$i<count($parts);$i+=2)
          $this->langs[] = array($parts[$i],$parts[$i+1]);
    }

    private function joinLanguageParts()
    {
        $this->text = $this->prefix;
        foreach ($this->langs as $lang)
        {
            $this->text .= '=={{-'.$lang[0].'-}}=='.PHP_EOL.PHP_EOL;
            $this->text .= $lang[1];
        }
    }

    //////////////////////////////////////////////////////////////////

    public function analyze()
    {
        $this->checkPrefix();
        $this->checkLangs();
    }

    private function checkPrefix()
    {
        if ($this->flags & self::CHECK_FOR_BROKEN_LANGS)
          foreach (self::$order as $l=>$_)
            if (strpos($this->prefix,'{{-'.$l.'-}}')!==false)
              $this->broken_langs[] = array('',$l);
    }

    private function checkLangs()
    {
        if ($this->flags & self::CHECK_FOR_BROKEN_LANGS)
          foreach ($this->langs as list($ll,$t))
            foreach (self::$order as $l=>$_)
              if (strpos($t,'{{-'.$l.'-}}')!==false)
                $this->broken_langs[] = array($ll,$l);

        if ($this->flags & self::CHECK_ORDER_OF_LANGS)
          $this->checkOrderOfLangs();
    }

    private function checkOrderOfLangs()
    {
        $report = false;
        $greek = false;
        $non_greek = false;
        $last = -1;
        $codes = array();
        foreach ($this->langs as list($ln,$_))
        {
            if (isset($codes[$ln]))
              @$this->double_langs[$ln]++;
            $codes[$ln] = true;

            if (!isset(self::$order[$ln]))
              @$this->unknown_langs[] = $ln;
            else
            {
                $nr = self::$order[$ln];
                if ($nr<INTERNATIONAL) $greek = true; else $non_greek = true;
                if ($nr<$last) $report = true;
                $last = $nr;
            }
        }

        if ($report)
        {
            if ($greek && $non_greek)
              $this->unsorted_word['mixed'] = true;
            else if ($greek)
              $this->unsorted_word['greek'] = true;
            else if ($non_greek)
              $this->unsorted_word['non_greek'] = true;
        }
    }
}

?>
