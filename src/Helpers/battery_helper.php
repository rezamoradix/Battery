<?php

use CodeIgniter\I18n\Time;
use Morilog\Jalali\Jalalian;
use Rey\Battery\Battery;

function battery($view, $data = [])
{
    $battery = new Battery($view, $data);
    return $battery->render();
}

function batframe($frame, $data = [])
{
    $battery = new Battery("frames/$frame", $data);
    return $battery->render();
}

function ee($data)
{
    if (config('Battery')->useJalali && ($data instanceof Time || $data instanceof DateTime))
        return jalali($data);

    return esc($data);
}

function jalali($date)
{
    if ($date === null) return null;
    $jd = jdate('Y/m/d H:i:s', $date->timestamp);
    date_default_timezone_set('UTC');
    return $jd;
}

function uploaded_url($url): string
{
    return site_url("uploads/$url");
}

function convertToOptions($items, $names, $value)
{
    $_items = [];
    $_names = is_array($names) ? $names : [$names];
    foreach ($items as $key => $i) {
        $_items[] = [
            'name' => implode(' | ', array_map(fn ($x) => $i[$x], $_names)),
            'value' => $i[$value],
        ];
    }
    return $_items;
}

function addValuesToFormData($formData, $values)
{
    for ($i = 0; $i < count($formData); $i++)
        $formData[$i]['value'] = $values[$formData[$i]['name']];

    return $formData;
}

function addValuesToFormData2($formData, $values)
{
    $keys = array_keys($formData);

    for ($i = 0; $i < count($formData); $i++)
        $formData[$keys[$i]]['value'] = $values[$keys[$i]];

    return $formData;
}

function b_isItMultipart($formData)
{
    return array_search("file", array_column($formData, 'type')) !== false;
}

function b_printFormEnctype($formData)
{
    return b_isItMultipart($formData) ? 'enctype="multipart/form-data"' : null;
}

function enToFaNum($en)
{
    if ($en === null) return null;
    return str_replace(
        ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', ','],
        ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٫'],
        $en
    );
}

function faNum($en)
{
    return enToFaNum($en);
}

function faNf($number)
{
    return enToFaNum(number_format($number ?? 0, 0, null, '٫'));
}

function enNf($number)
{
    return (number_format($number ?? 0, 0, null, ','));
}

function usdNf($number)
{
    return (number_format($number ?? 0, 2, null, ','));
}

function tomans($number)
{
    return faNf($number < 0 ? $number * -1 : $number) . " تومان";
}

function dollars($number)
{
    return enNf($number < 0 ? $number * -1 : $number) . "$";
}

function tomansEn($number)
{
    return enNf($number) . " تومان";
}

function irPhone($str): bool
{
    return (bool) preg_match('/\A09\d{9}+\z/i', $str ?? "");
}

function translatePersianFieldType(string $type)
{
    switch ($type) {
        case 'کادر':
            return "text";
        case 'متن':
            return "textarea";
        case 'لیست':
            return "select";
        case 'فایل':
            return "file";
        case 'تیک':
            return "checkbox";

        default:
            return null;
    }
}

function getBigNumber($max)
{
    # prevent the first number from being 0
    $output = rand(1, 9);

    for ($i = 0; $i < $max; $i++) {
        $output .= rand(0, 9);
    }

    return $output;
}

function carbon(...$params)
{
    return new \Carbon\Carbon(...$params);
}

function lc_random_string(int $len = 8): string
{
    $pool = 'abcdefghijklmnopqrstuvwxyz';
    return _from_random($len, $pool);
}

function lc_random_number(int $len = 8): string
{
    $pool = '0123456789';
    return _from_random($len, $pool);
}

function b_stim_data($data) {
    $attrs = [];
    foreach ($data as $key => $value) {
        $attrs[] = "data-$key=\"$value\"";
    }
    return implode(" ", $attrs);
}
