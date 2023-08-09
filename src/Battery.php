<?php

namespace Rey\Battery;

use DateTimeZone;
use Morilog\Jalali\Jalalian;

class Battery
{
    protected $CI;
    protected $out = "";
    protected $view_name = "";
    protected $data = [];
    protected $cache_dir = "_battery";
    protected $gen_js_dir = "js";
    protected $gen_css_dir = "css";
    protected $babelenv = WRITEPATH . 'babelenv';

    /**
     * Battery Config
     *
     * @var BatteryConfig
     */
    private $config;

    private $view_path = null;
    private $hashes = [];
    private $new_hashes = [];
    private $parents_hashes = [];

    public $debug = false;
    public $trim = false;
    public $force_render = false;


    public function __construct($view_name = "", $data = [])
    {
        $this->config = config('Battery');

        $this->view_name = $this->config->getTheme() . ($this->config->hasAlias($view_name) ? $this->config->getAlias($view_name) : $view_name);

        $this->data = $data;
        $this->view_path = APPPATH . 'Views' . DIRECTORY_SEPARATOR;

        $this->debug = ENVIRONMENT == 'development';

        helper('battery');
        helper('filesystem');
        helper('inflector');
        helper('text');


        if ($this->debug) {
            $this->get_hashes();
        }
    }

    public static function show($view_name = "", $data = [])
    {
        $self = new self(str_replace('\\', '/', $view_name), $data);
        return $self->render();
    }

    public function render()
    {
        if ($this->updated()) {
            $this->_render();
        }

        return view($this->gen_cache_path($this->view_name), $this->data);
    }

    public function updated()
    {
        if ($this->debug) return true;

        if (!is_file($this->view_path . $this->gen_cache_path($this->view_name)))
            return true;


        /* if ($this->debug) return true;

        if (!is_file($this->view_path . $this->gen_cache_path($this->view_name))) {
            return true;
        }

        $gend = $this->get($this->gen_cache_path($this->view_name));
        preg_match("/gen_view_hash=(\w+)/sui", $gend, $view_hash);
        if ($this->md5_view($this->view_name) != $view_hash[1]) {
            return true;
        }

        preg_match("/gen_parents_hashes=(.*?)\s/sui", $gend, $name);
        if (isset($name[1])) {
            $parents = explode(';', $name[1]);

            foreach ($parents as $key => $par) {
                if ($par) {
                    list($pname, $phash) = explode(':', $par);
                    if ($this->md5_view($pname) != $phash) {
                        return true;
                    }
                }
            }
        } */

        return false;
    }

    private function _render()
    {
        log_message("info", "[Battery] #Rendering; #view={$this->view_name}");
        $raw = $this->get($this->view_name);

        if (($pn = $this->parent_name($raw))) {
            $parent = $this->get($pn);
            $parent_md5 = $this->md5_view($pn);
            $view_md5 = $this->md5_view($this->view_name);

            $parent_name = str_replace('\\', '/', $pn);

            $p_rendered = $this->render_parent($parent, $raw);

            $this->parents_hashes[] = "$parent_name:$parent_md5";

            $php_code = $this->php_comment([
                'view_name' => $this->view_name,
                'gen_parent_name' => $parent_name,
                'gen_parent_hash' => $parent_md5,
                'gen_view_hash' => $view_md5,
            ], true);

            $this->out = $php_code . $p_rendered;
        } else {
            $view_md5 = $this->md5_view($this->view_name);
            $rendered = $this->render_vars($raw);
            $php_code = $this->php_comment([
                'view_name' => $this->view_name,
                'gen_view_hash' => $view_md5
            ], true);
            $this->out = $php_code . $rendered;
        }

        $this->write_cache();
        $this->write_cache_class();

        return $this;
    }

    public function parent_name($content)
    {
        preg_match("/@@parent\s*?=\s*(.*?)\s/sui", $content, $extend);
        return isset($extend[1]) ? $this->config->getTheme() . $extend[1] : false;
    }

    public function render_parent($parent, $child)
    {
        preg_match_all("/@@=(\w+)/sui", $parent, $pls);
        if (!empty($pls[1])) {
            foreach ($pls[1] as $key => $pl) {
                preg_match("/@@$pl(.*?)@@stop/sui", $child, $section);
                $parent = str_replace("@@=$pl", (isset($section[1]) ? trim($section[1]) : ''), $parent);
            }
        }

        $pn = $this->parent_name($parent);

        if ($pn) {
            $parent_name = str_replace('\\', '/', $pn);
            $parent_md5 = $this->md5_view($pn);
            $this->parents_hashes[] = "$parent_name:$parent_md5";
        }

        return $this->render_vars($pn ? $this->render_parent(
            $this->get($pn),
            $parent
        ) : $parent);
    }

    public function render_vars($content)
    {
        // Auto-Variables ($_auth, )
        $pre_content = '';

        $content = str_replace("@@csrf", $this->php_echo("csrf_field"), $content);
        $content = str_replace("@@qs", $this->php_echo("qs"), $content);

        // Language Loader
        $content = preg_replace(
            "/@@lang\s*?=\s*(.*?)\s*,\s*(.*?)\s*@@/sui",
            '<?php $this->lang->load("$1", "$2"); ?>',
            $content
        );


        // AUTH & GUEST (CI Shield)
        $content = preg_replace("/@@guest/sui", "<?php if (!auth()->loggedIn()) : ?>", $content);
        $content = preg_replace("/@@endguest/sui", "<?php endif; ?>", $content);
        $content = preg_replace("/@@auth/sui", "<?php if (auth()->loggedIn()) : ?>", $content);
        $content = preg_replace("/@@endauth/sui", "<?php endif; ?>", $content);

        // IF STATEMENT
        $content = preg_replace("/@@isset\s*(.*?)\s*@@/sui", "<?php if (isset($1)): ?>", $content);
        $content = preg_replace("/@@endisset/sui", "<?php endif; ?>", $content);
        $content = preg_replace("/@@if\s*(.*?)\s*@@/sui", "<?php if ($1): ?>", $content);
        $content = preg_replace("/@@endif/sui", "<?php endif; ?>", $content);
        $content = preg_replace("/@@elseif\s*(.*?)\s*@@/sui", "<?php elseif ($1): ?>", $content);
        $content = preg_replace("/@@else/sui", "<?php else: ?>", $content);

        // @@notempty $var @@
        $content = preg_replace("/@@notempty\s*(.*?)\s*@@/sui", "<?php if (!empty($1)): ?>", $content);
        $content = preg_replace("/@@endnotempty/sui", "<?php endif; ?>", $content);

        // ISSET-IF
        // @@iif :$var: [your if statemnt] @@
        $content = preg_replace("/@@iif\s*\:(.*?)\:\s*(.*?)\s*@@/sui", "<?php if (isset($1) && $2): ?>", $content);

        // FOREACH
        $content = preg_replace("/@@foreach\s*(.*?)\s*@@/sui", "<?php foreach ($1): ?>", $content);

        // Arrow Foreach @@ $item, $key in $items => #CONTENT# @@
        $content = preg_replace("/@@\s*(.*?)\s*,\s*(.*?)\s+in\s+(.*?)\s*=>\s*(.*?)\s*@@/sui", "<?php foreach ($3 as $2 => $1): ?> $4 <?php endforeach; ?>", $content);
        $content = preg_replace("/@@\s*(.*?)\s+in\s+(.*?)\s*=>\s*(.*?)\s*@@/sui", "<?php foreach ($2 as $1): ?> $3 <?php endforeach; ?>", $content);

        $content = preg_replace("/@@endforeach/sui", "<?php endforeach; ?>", $content);

        // FOR
        $content = preg_replace("/@@for\s*(.*?)\s*@@/sui", "<?php for ($1): ?>", $content);
        $content = preg_replace("/@@endfor/sui", "<?php endfor; ?>", $content);

        // INCLUDE
        // @@include VIEW @@
        $content = preg_replace("/@@includeWhenWith\s*(.*?)\s*,\s*(.*?)\s*,\s*(.*?)\s*@@/sui", "<?php if ($1) echo battery($2, $3); ?>", $content);
        $content = preg_replace("/@@includeWhen\s*(.*?)\s*,\s*(.*?)\s*@@/sui", "<?php if ($1) echo battery($2); ?>", $content);
        $content = preg_replace("/@@includeWith\s*(.*?),\s*(.*?)\s*@@/sui", "<?php echo battery($1, $2); ?>", $content);
        $content = preg_replace("/@@include\s*(.*?)\s*@@/sui", "<?php echo battery($1); ?>", $content);

        $content = preg_replace("/@d@include\s*(.*?)\s*@@/sui", "<?php echo view($1); ?>", $content);
        $content = preg_replace_callback("/@c@include\s*(.*?)\s*@@/sui", function ($t_name) {
            return $this->get(str_replace(["\"", "'"], '', $t_name[1]));
        }, $content);

        $content = $this->render_bTag($content);


        // PHP COMMENT, HTML COMMENT
        $content = preg_replace("/{{\*\s*(.*?)\s*\*}}/sui", "<?php /* $1 */ ?>", $content);
        $content = preg_replace("/{{--\s*(.*?)\s*--}}/sui", "<!-- $1 -->", $content);

        // ECHO: MODES
        $content = preg_replace("/{{\s*@([a-zA-Z0-9_]+?)\s+(.*?)\s*}}/sui", "{{ $1($2) }}", $content);

        // @@ CONSTS
        $content = $this->render_consts($content);

        // ECHO, PHP CODE
        // {{ ?$condition? $var2 }} {{ if ($condition) $var }}
        $content = preg_replace("/{{\s*\?\s*(.*?)\s*\?\s*(.*?)\s*}}/sui", "<?php if ($1) echo ee($2); ?>", $content);
        // {{ :$var: }} {{ if (isset($var)) $var }}
        $content = preg_replace("/{{\s*:\s*(.*?)\s*:\s*}}/sui", "<?php if (isset($1)) echo ee($1); ?>", $content);
        // {{ $var }}
        $content = preg_replace("/{{\s*(.*?)\s*}}/sui", "<?php echo ee($1); ?>", $content);
        // {!! :$var: !!}
        $content = preg_replace("/{!!\s*:\s*(.*?)\s*:\s*!!}/sui", "<?php if (isset($1)) echo ee($1, false); ?>", $content);
        // {!! $var !!}
        $content = preg_replace("/{!!\s*(.*?)\s*!!}/sui", "<?php echo $1; ?>", $content);
        // [[ PHP-CODE ]]
        $content = preg_replace("/\[\[\s*(.*?)\s*\]\]/sui", "<?php $1 ?>", $content);
        // {%d var %} = {{ lang("default.var") }}
        $content = preg_replace("/{%d\s*(.*?)\s*%}/sui", "<?php echo ee(lang('Default.' . $1)); ?>", $content);
        // {% $var %} = {{ lang($var) }}
        $content = preg_replace("/{%\s*(.*?)\s*%}/sui", "<?php echo ee(lang($1)); ?>", $content);



        // JQUERY
        $content = $this->render_jquery($content);

        // BABEL-JS
        $_md5 = md5($this->view_name);
        $_js_counter = 0;
        $preset = null;
        $content = preg_replace_callback("/@@babel(.*?)@@endbabel/sui", function ($a_js) use ($_md5, &$_js_counter, $preset) {
            if ($preset == null) {
                $preset = $this->get_babel_env();
            }

            $_js_counter++;
            $_js_path = $this->view_path . $this->gen_cache_path($this->view_name, false) . '_js_' . $_js_counter . '.js';
            $md5_js = md5($a_js[1]);

            if (in_array($md5_js, $this->hashes)) {
                $_js_content = file_get_contents($_js_path);
                $this->new_hashes[] = $md5_js;
                return "<script>$_js_content</script>";
            }

            $this->new_hashes[] = $md5_js;

            $a_js = preg_replace('/\<script.*?\>/sui', '', $a_js[1]);
            $a_js = str_replace(['<script>', '</script>'], '', $a_js);
            write_file($_js_path, trim($a_js));
            exec("babel \"$_js_path\" -o \"$_js_path\" --presets=\"$preset\"");
            exec("uglifyjs \"$_js_path\" -c -o \"$_js_path\"");
            $_js_content = file_get_contents($_js_path);
            return "<script>$_js_content</script>";
        }, $content);

        // GenerateJS,CSS
        $_js_counter = 0;
        $content = preg_replace_callback("/@@genjs(.*?)@@endgen/sui", function ($a_js) use ($_md5, &$_js_counter) {
            $_js_counter++;
            $_js_path = $this->gen_js_dir . '/' . $_md5 . '_' . $_js_counter . '.js';
            $md5_js = md5($a_js[1]);
            if (!in_array($md5_js, $this->hashes)) {
                $this->new_hashes[] = $md5_js;
            } else {
                $this->new_hashes[] = $md5_js;
                return '<script type="text/javascript" src="/' . ltrim($_js_path, '/') . '"></script>';
            }
            $a_js = preg_replace('/\<script.*?\>/sui', '', $a_js[1]);
            $a_js = str_replace(['<script>', '</script>'], '', $a_js);
            write_file($_js_path, trim($a_js));
            exec("uglifyjs \"$_js_path\" -c -o \"$_js_path\"");
            return '<script type="text/javascript" src="/' . ltrim($_js_path, '/') . '"></script>';
        }, $content);

        $_css_counter = 0;

        $content = preg_replace_callback("/@@gencss\s*(.*?)\s*@@endgen/sui", function ($a_css) use ($_md5, &$_css_counter) {
            $_css_counter++;
            $_css_path = $this->gen_css_dir . '/' . $_md5 . '_' . $_css_counter . '.css';

            $md5_css = md5($a_css[1]);
            if (!in_array($md5_css, $this->hashes)) {
                $this->new_hashes[] = $md5_css;
            } else {
                $this->new_hashes[] = $md5_css;
                return '<link rel="stylesheet" type="text/css" href="' . base_url($_css_path) . '">';
            }

            $a_css = str_replace(['<style>', '</style>'], '', $a_css[1]);
            write_file($_css_path, trim($a_css));
            exec("cleancss -o $_css_path $_css_path");
            return '<link rel="stylesheet" type="text/css" href="' . base_url($_css_path) . '">';
        }, $content);

        $_css_counter = 0;
        $content = preg_replace_callback("/@@genstyle\s*(.*?)\s*@@endgen/sui", function ($a_css) use ($_md5, &$_css_counter) {
            $_css_counter++;
            $_css_path = $this->view_path . $this->gen_cache_path($this->view_name, false) . '_css_' . $_css_counter . '.css';

            $md5_css = md5($a_css[1]);
            if (!in_array($md5_css, $this->hashes)) {
                $this->new_hashes[] = $md5_css;
            } else {
                $_css_content = file_get_contents($_css_path);
                $this->new_hashes[] = $md5_css;
                return "<style>$_css_content</style>";
            }

            $a_css = str_replace(['<style>', '</style>'], '', $a_css[1]);
            write_file($_css_path, trim($a_css));
            exec("cleancss -o $_css_path $_css_path");
            $_css_content = file_get_contents($_css_path);
            return "<style>$_css_content</style>";
        }, $content);


        // Removing unused sections
        $content = preg_replace("/@@=(\w+)/sui", '', $content);

        // @@ Escape (usage: @@/)
        $content = str_replace('@@/', '@@', $content);

        // {{}} Escape (usage: {{/  /}})
        $content = str_replace('{{/', '{{', $content);
        $content = str_replace('/}}', '}}', $content);

        // TRIM SPACES
        if ($this->trim) {
            $content = preg_replace("/\>\s+\</si", "><", $content);
        }

        return $pre_content . $content;
    }

    public function render_consts($content)
    {
        /**
         * @@stimController data-controller="$field['controller']"
         * @@stimTarget data-$field['controller']-target="$field['name']"
         * @@stimData data-$field['data'][KEY]="$field['data'][KEY][VALUE]"
         */

        $content = preg_replace('/@@stimController\s*\((.*?)\)/i', '[[ $__stimController = "$1"; ]]data-controller="$1"', $content);
        $content = preg_replace('/@@stimTarget\s\(.*?\)/i', 'data-{{ $__stimController }}-target="$1"', $content);
        $content = preg_replace('/@@stimData\s\(.*?\)\s*=\"(.*?)\"/i', 'data-$1-target="{{ $2 }}"', $content);

        $content = str_replace('@@stimController', 'data-controller="{{ $field[\'controller\'] }}"', $content);
        $content = str_replace('@@stimTarget', 'data-{{ $field[\'controller\'] }}-target="{{ $field[\'name\'] }}"', $content);
        $content = str_replace('@@stimData', '{!! b_stim_data($field[\'data\']) !!}', $content);

        return $content;
    }

    public function render_jquery($content)
    {
        $content = preg_replace("/@@jq\.click\s*\<(.*?)\>\s/is", "$(document).on('click', '\$1', function(e){ ", $content);
        $content = preg_replace("/@@endjq/is", "});", $content);

        // DoubleQoutation
        $content = preg_replace_callback("/@@dq(.*?)@@enddq/si", function ($qoute) {
            $_qoute = str_replace("\r\n", "\n", $qoute[1]);
            $lines = explode("\n", $_qoute);
            $combined = "";
            foreach ($lines as $key => $line) {
                $cc = trim(str_replace('"', '\"', $line));
                if ($cc !== "") {
                    $combined .= '"' . $cc . '"' .
                        (isset($lines[$key + 1]) && trim(str_replace('"', '\"', $lines[$key + 1])) !== "" ? "+" : '');
                }
            }
            return $combined;
        }, $content);

        return $content;
    }

    private function render_bTag($content)
    {
        $content = $this->render_bComponents($content);

        $content = preg_replace(
            "/<b-if\s+(.*?)\s*>/i",
            '<?php if ($1): ?> ',
            $content
        );

        $content = preg_replace(
            "/<b-isset\s+(.*?)\s*>/i",
            '<?php if (isset($1)): ?> ',
            $content
        );

        $content = preg_replace(
            "/<b-has\s+(.*?)\s*>/i",
            '<?php if (has_permission("$1")): ?> ',
            $content
        );

        $content = preg_replace(
            "/<b-repeat\s+(.*?)\s*>/i",
            '<?php for ($i = 0; $i < $1; $i++): ?> ',
            $content
        );

        $content = preg_replace(
            "/<b-each\s+(.*?)\s*,\s*(.*?)\s+in\s+(.*?)\s*>/i",
            "<?php foreach ($3 as $2 => $1): ?> ",
            $content
        );

        $content = preg_replace(
            "/<b-each\s+(.*?)\s+in\s+(.*?)\s*>/i",
            "<?php foreach ($2 as $1): ?> ",
            $content
        );

        $content = preg_replace(
            "/<\/b-each>/sui",
            " <?php endforeach; ?>",
            $content
        );

        $content = preg_replace(
            "/<\/b-repeat>/sui",
            " <?php endfor; ?>",
            $content
        );

        $content = preg_replace(
            "/<\/b-has>/sui",
            " <?php endif; ?>",
            $content
        );

        $content = preg_replace(
            "/<\/b-isset>/sui",
            " <?php endif; ?>",
            $content
        );

        $content = preg_replace(
            "/<\/b-if>/sui",
            " <?php endif; ?>",
            $content
        );

        $content = $this->render_bGenJs($content);

        $content = preg_replace("/<b-php>/sui", "<?php ", $content);
        $content = preg_replace("/<\/b-php>/sui", "?> ", $content);

        return $content;
    }

    private function render_bGenJs(string $content)
    {
        // BABEL-JS
        $_md5 = md5($this->view_name);
        $_js_counter = 0;
        $preset = null;
        $content = preg_replace_callback("/<rv-babel\s*(.*?)\s*>(.*?)<\/rv-babel>/sui", function ($a_js) use ($_md5, &$_js_counter, $preset) {
            if ($preset == null) {
                $preset = $this->get_babel_env();
            }

            $_js_counter++;
            $_js_path = $this->view_path . $this->gen_cache_path($this->view_name, false) . '_js_' . $_js_counter . '.js';
            $md5_js = md5($a_js[2]);

            if (in_array($md5_js, $this->hashes)) {
                $_js_content = file_get_contents($_js_path);
                $this->new_hashes[] = $md5_js;
                return "<script>$_js_content</script>";
            }

            $this->new_hashes[] = $md5_js;

            // $the_js_content = preg_replace('/\<script.*?\>/sui', '', $a_js[2]);
            // $a_js = str_replace(['<script>', '</script>'], '', $the_js_content);
            preg_match('/\<script.*?\>(.*?)<\/script>/sui', $a_js[2], $_matches_);
            $the_js_content = $_matches_[1];
            write_file($_js_path, trim($the_js_content));
            exec("babel \"$_js_path\" -o \"$_js_path\" --presets=\"$preset\"");

            $props = explode(' ', $a_js[1]);
            if (in_array('uglify', $props) || in_array('uglifyjs', $props) || in_array('minify', $props)) {
                exec("uglifyjs \"$_js_path\" -c -o \"$_js_path\"");
            }

            $_js_content = file_get_contents($_js_path);
            return "<script>$_js_content</script>";
        }, $content);

        $_js_counter = 0;
        $content = preg_replace_callback("/<rv-genjs\s*(.*?)\s*>(.*?)<\/rv-genjs>/sui", function ($a_js) use ($_md5, &$_js_counter) {
            $_js_counter++;
            $_js_path = $this->gen_js_dir . '/' . $_md5 . '_' . $_js_counter . '.js';
            $md5_js = md5($a_js[2]);
            if (!in_array($md5_js, $this->hashes)) {
                $this->new_hashes[] = $md5_js;
            } else {
                $this->new_hashes[] = $md5_js;
                return '<script type="text/javascript" src="/' . ltrim($_js_path, '/') . '"></script>';
            }
            $the_js_content = preg_replace('/\<script.*?\>/sui', '', $a_js[2]);
            $the_js_content = str_replace(['<script>', '</script>'], '', $the_js_content);
            file_put_contents($_js_path, trim($the_js_content));

            $props = explode(' ', $a_js[1]);
            if (in_array('uglify', $props) || in_array('uglifyjs', $props) || in_array('minify', $props)) {
                exec("uglifyjs \"$_js_path\" -c -o \"$_js_path\"");
            }

            return '<script type="text/javascript" src="/' . ltrim($_js_path, '/') . '"></script>';
        }, $content);

        return $content;
    }

    private function render_bComponents(string $content)
    {
        /* // rv: with props and children
        $content = preg_replace_callback("/<b:(.*?)\s+(.*?)\s*\/><>/sui", function ($rv) {
            preg_match_all("/([A-Za-z_0-9]*?)=\"(.*?)\"/sui", $rv[2], $props);
            $genProps = '[';
            for ($i=0; $i < count($props[1]); $i++) {
                $genProps .= "'" . $props[1][$i] . "' => " . $props[2][$i] . ",";
            }
            $genProps .= ']';
            $componentName = str_replace('\\', '/', $rv[1]);
            return "<?php echo battery('$componentName', $genProps); ?>";
        }, $content); */

        // rv: with props
        $content = preg_replace_callback("/<b:(.*?)\s+(.*?)\s*\/>/sui", function ($rv) {
            $propsAsStrings = [];

            if (isset($rv[2])) {
                preg_match_all("/([:A-Za-z_0-9]*?)=\"(.*?)\"/sui", $rv[2], $match);

                if (isset($match[1]) && isset($match[2])) {
                    $propKeys = $match[1];
                    $propVals = $match[2];
                    $attributeCount = count($propKeys);

                    for ($i = 0; $i < $attributeCount; $i++) {
                        $key = $propKeys[$i];
                        $val = $propVals[$i];
                        $isString = $key[0] !== ":";
                        $propsAsStrings[] = "'" . ($isString ? $key : substr($key, 1)) . "' => " . ($isString ? "'$val'" : $val);
                    }
                }
            }

            $joined = implode(',', $propsAsStrings);

            $componentName = str_replace(['\\', '.'], '/', $rv[1]);
            return "<?php echo battery('$componentName', [$joined]); ?>";
        }, $content);

        // rv:
        /* $content = preg_replace("/<rv:(.*?)\/>/sui", "<?php echo battery('$1'); ?>", $content); */

        // rv-inject: with props
        $content = preg_replace_callback("/<b-inject:([A-Za-z0-9_\.]+)\s+(.*?)\/>/sui", function ($rv) {
            //log_message('error', print_r($rv, true));
            preg_match_all("/([:A-Za-z_0-9]*?)=\"(.*?)\"/sui", $rv[2], $props);
            $genProps = '[';
            for ($i = 0; $i < count($props[1]); $i++) {
                $key = $props[1][$i];
                $val = $props[2][$i];
                $isString = $key[0] !== ":";
                $genProps .= "'" . ($isString ? $key : substr($key, 1)) . "' => " . ($isString ? "'$val'" : $val) . ",";
            }
            $genProps .= ']';

            // $data = array_combine($props[1], $props[2]);
            log_message('error', $genProps);

            $componentName = str_replace('\\', '/', $rv[1]);
            $componentName = str_replace('.', '/', $rv[1]);

            ob_start();
            $incoming = " echo battery('$componentName', $genProps); ?>";
            eval($incoming);
            $output = ob_get_contents();
            @ob_end_clean();

            return $output;
        }, $content);

        // rv-inject
        /* $content = preg_replace("/<rv:(.*?)\s+\/>/sui", "<?php echo battery('$1'); ?>", $content); */

        return $content;
    }

    // public static function is_auth()
    // {
    //     return ConfigServices::auth()->isLoggedIn();
    // }

    // public static function auth_user()
    // {
    //     return ConfigServices::auth()->auth_user();
    // }

    // public static function get_option($name)
    // {
    //     return Option::getOption($name);
    // }

    public static function toPersianDate($format, $timestamp, $tz = "Asia/Tehran")
    {
        return Jalalian::fromFormat($format, $timestamp, new DateTimeZone($tz));
    }

    public function php_comment($arr, $with_hashes = false)
    {
        $className = "namespace App\\Views\\_battery;\n";
        $text = "<?php" . PHP_EOL . "/*" . PHP_EOL;
        foreach ($arr as $key => $value) {
            $text .= ' * ' . $key . '=' . $value . PHP_EOL;
        }
        if ($with_hashes) {
            $text .= ' * hashes=' . implode(',', $this->new_hashes) . PHP_EOL;
        }

        // Generated parents [name:hash];
        $text .= ' * gen_parents_hashes=' . implode(';', $this->parents_hashes) . PHP_EOL;
        $text .= ' */ ?>';
        return $text;
    }

    public function php_echo($func)
    {
        return "<?php echo " . $func . "(); ?>";
    }

    public function write_cache()
    {
        write_file($this->view_path . $this->gen_cache_path($this->view_name), $this->out);
    }

    public function write_cache_class()
    {
        $className = "Class_" . str_replace(['/', '\\', '-'], '_', $this->view_name);

        $classCache = implode("\n", [
            "<?php",
            "namespace App\\Views\\_battery;",
            "class $className {}",
            "include '" . str_replace(["\\", "/"], ".", $this->view_name) . '.php' . "';"
        ]);

        write_file($this->view_path . $this->cache_dir . '/' . $className . '.php', $classCache);
    }

    public function path($view_name)
    {
        return str_replace('\\', '/', $this->view_path . (strpos($view_name, '.html') !== false ? $view_name : $view_name . '.html'));
    }

    public function md5_view($view_name)
    {
        return md5_file($this->path($view_name));
    }

    public function gen_cache_path($view_name, $with_php = true)
    {
        log_message('info', $view_name);
        return $this->cache_dir . '/' . str_replace(["\\", "/"], ".", $view_name) . ($with_php ? '.php' : '');
    }

    private function current_view_path()
    {
        return $this->gen_cache_path($this->view_name);
    }

    private function get_babel_env()
    {
        return is_file($this->babelenv) ? file_get_contents($this->babelenv) : $this->write_babel_env();
    }

    private function get_hashes()
    {
        if (is_file($this->path($this->current_view_path()))) {
            $data = $this->get($this->gen_cache_path($this->view_name));
            preg_match("/hashes=([\w,]+)/sui", $data, $gen_hashes);
            if (isset($gen_hashes[1])) {
                $this->hashes = explode(',', $gen_hashes[1]);
            }
        }
    }

    private function write_babel_env()
    {
        $env = exec("npm -g root") . '\\@babel\\preset-env';
        file_put_contents($this->babelenv, $env);
        return $env;
    }

    public function get($view_name)
    {
        return file_get_contents($this->path($view_name));
    }
}
