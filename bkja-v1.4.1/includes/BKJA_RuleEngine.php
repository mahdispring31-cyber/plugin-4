<?php
if (!defined('ABSPATH')) exit;

class BKJA_RuleEngine {

    public static function classify($text) {
        $t = mb_strtolower($text, 'UTF-8');

        if (preg_match('/(اطلاعات شغل|کارت شغلی|درخواست اطلاعات شغل|تجربه)/u', $t)) {
            return 'TYPE_A';
        }

        if (preg_match('/(درآمد|حقوق|حکم|دریافتی|چنده|چقدره|ماهی|ماهانه)/u', $t)
            && mb_strlen($t) < 80) {
            return 'TYPE_B';
        }

        return 'TYPE_C';
    }

    public static function normalize_income($text) {
        $t = self::fa_to_en($text);

        if (!preg_match('/(\d+(?:\.\d+)?)/', $t, $m)) return null;
        $num = floatval($m[1]);

        $salary_ctx = preg_match('/(حقوق|حکم|دریافتی|ماهانه|ماهی|دستمزد)/u', $t);
        $has_million = strpos($t, 'میلیون') !== false;
        $has_billion = strpos($t, 'میلیارد') !== false;
        $has_thousand = strpos($t, 'هزار') !== false;

        $toman = $num;

        if ($has_billion)       $toman *= 1000000000;
        elseif ($has_million)  $toman *= 1000000;
        elseif ($has_thousand) $toman *= 1000;
        else {
            if ($salary_ctx && $num <= 100000) {
                $toman *= 1000;
            }
        }

        return round($toman / 1000000, 2); // million toman
    }

    private static function fa_to_en($s) {
        return str_replace(
            ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'],
            ['0','1','2','3','4','5','6','7','8','9'],
            $s
        );
    }
}
