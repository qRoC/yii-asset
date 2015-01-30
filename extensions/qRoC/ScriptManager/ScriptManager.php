<?php
/**
 * @author Andrey Savitskiy <qRoC.Work@gmail.com>
 * @copyright 2014-2015 qRoC
 */


function closure($obj, $method)
{
    return (new ReflectionMethod($obj, $method))->getClosure($obj);
}

class LessCompiler
{
    static public $less_import_dirs = [];

    public static function create()
    {
        static $instance = false;

        if (!$instance)
        {
            Yii::import('vendor.*');
            require_once('less.php/Less.php');
            $instance = true;
        }

        $parser =  new Less_Parser();
        $parser->SetImportDirs(self::$less_import_dirs);

        return $parser;
    }
}

class CssMin
{
    public static function create()
    {
        static $instance = FALSE;

        if (!$instance)
        {
            Yii::import('vendor.*');
            require_once('csstidy/class.csstidy.php');
            $instance = TRUE;

            $instance = new csstidy();
            $instance->load_template('highest_compression');

            foreach ( [
                          //'merge_selectors'            => 2,
                      ] as $k => $v )
            {
                $instance->set_cfg($k, $v);
            }
        }

        return $instance;
    }
}

class AssetCreator
{
    private $real_src;
    private $real_format = '';
    private $hash;
    private $src;
    private $url;

    function __construct($src, $hash = false, $real_format = null)
    {
        $this->real_src = $src;
        $this->hash = $hash;

        if (!$this->real_src)
        {
            if (!$hash || !$real_format)
                throw new CException(__CLASS__ . 'Bad args');

            $this->real_format = $real_format;

        } elseif (is_file($this->real_src))
            $this->real_format = $real_format ? $real_format : pathinfo( $this->real_src, PATHINFO_EXTENSION );

        /*
        $updater = function($method)
        {
            $inner = closure($this, $method);

            return function() use($inner)
            {
                call_user_func_array($inner, func_get_args());
                $this->updateAccessTime();
            };
        };

        $this->copy   = $updater('innerCopy');
        $this->create = $updater('innerCreate');
        */
    }

    public function needUpdate()
    {
        $path = $this->path();

        $al_ok = file_exists($path);

        //if (!$al_ok) echo $path . @filemtime($path) . PHP_EOL;

        if ($al_ok && $this->real_src)
            $al_ok &= filemtime($path) == filemtime($this->real_src);

        return !$al_ok;
    }

    public function path()
    {
        if (!$this->src)
        {
            if (!$this->real_src)
                $filename = $this->hash . '.' . $this->real_format;
            elseif ($this->hash)
                $filename = md5($this->real_src) . '.' . $this->real_format;
            else
                $filename = basename($this->real_src);

            $this->src = implode([ Yii::app()->assetManager->basePath,
                                   $filename
            ], DIRECTORY_SEPARATOR);
        }

        return $this->src;
    }

    public function urlPath()
    {
        if (!$this->url)
        {
            $this->url = str_replace(Yii::app()->assetManager->basePath,
                                     Yii::app()->assetManager->baseUrl,
                                     $this->path());
        }

        return $this->url;
    }

    static function urlToPath($url)
    {
        return str_replace(Yii::app()->assetManager->baseUrl,
                           Yii::app()->assetManager->basePath,
                           $url);
    }

    public function create($data)
    {
        file_put_contents($this->path(), $data);

        $this->updateAccessTime();
    }

    public function copy()
    {
        if (!$this->real_src)
            return;

        $path = $this->path();

        if (is_file($this->real_src))
        {
            @copy($this->real_src, $path);
        }
        else
        {
            @mkdir($path, 0755);

            foreach (
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($this->real_src, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST) as $item
            )
            {
                $dst = $path . DIRECTORY_SEPARATOR . $iterator->getSubPathName();

                if ($item->isDir())
                    @mkdir($dst);
                else
                    @copy($item, $dst);
            }
        }

        $this->updateAccessTime();
    }

    private function updateAccessTime()
    {
        if ($this->real_src)
            @touch($this->path(), filemtime($this->real_src));
    }

    public static function garbageCollect()
    {
        $ttl = function($path)
        {
            $ttl = 7 * 60 * 60 * 24; // 7 дней.

            return (fileatime($path) + $ttl) < time();
        };

        $files = CFileHelper::findFiles( Yii::app()->assetManager->basePath, [
                'fileTypes' => [ 'css', 'js' ],
                'level'     => 0
            ] );

        foreach ($files as $file)
            if ($ttl($file))
                @unlink($file);
    }
}

class ScriptManager extends CClientScript
{
    // config
    public $combine_css = false;
    public $combine_js  = false;
    public $caching     = true;
    public $less_import_dirs = [];

    // protected
    protected $resourceFiles = [];

    public function init()
    {
        if (YII_DEBUG)
            AssetCreator::garbageCollect();

        foreach ($this->less_import_dirs as &$dir)
        {
            $path = Yii::getPathOfAlias($dir);

            LessCompiler::$less_import_dirs[$path] = '.';
        }

        parent::init();
    }

    // TODO
    public function render(&$output)
    {
        if (!$this->hasScripts)
            return;

        if ($this->coreScripts !== null)
            $this->renderCoreScripts();

        $this->unifyScripts();

        $this->renderHead($output);
        $this->renderBodyBegin($output);
        $this->renderBodyEnd($output);
    }

    public function renderCoreScripts()
    {
        $real_path = '';

        $apply = function($src, &$dst, Closure $cb = null) use(&$real_path)
        {
            if ($cb === null)
                $cb = closure((Yii::app()->assetManager), 'publish');

            foreach ( $src as $el )
                $dst[$cb($real_path . $el)] = '';
        };

        foreach ($this->coreScripts as $package)
        {
            if ($package['path'])
                $real_path = Yii::getPathOfAlias($package['path']) . DIRECTORY_SEPARATOR;

            if (!empty($package['css']))
                $apply($package['css'], $this->cssFiles);

            if (!empty($package['less']))
                $apply($package['less'], $this->cssFiles, closure($this, 'compileLess'));

            if (!empty($package['js_head']))
                $apply($package['js_head'], $this->scriptFiles[self::POS_HEAD]);

            if (!empty($package['js']))
                $apply($package['js'], $this->scriptFiles[self::POS_END]);

            if (!empty($package['resources']))
                $apply($package['resources'], $this->resourceFiles, closure($this, 'copyResource'));
        }
    }

    protected function copyResource($source_path)
    {
        $asset = new AssetCreator($source_path);

        if (!$this->caching || $asset->needUpdate())
            $asset->copy();

        return $asset->urlPath();
    }

    protected function compileLess($source_path)
    {
        $asset = new AssetCreator($source_path, true, 'css');

        if (!$this->caching || $asset->needUpdate())
        {
            try
            {
                $parser = LessCompiler::create();
                $parser->parseFile( $source_path );

                $asset->create($parser->getCss());
            }
            catch(Exception $e)
            {
                throw new CException(__CLASS__.': Failed to compile less file with message: '.$e->getMessage().'.');
            }
        }

        return $asset->urlPath();
    }

    public function renderHead(&$output)
    {
        $html = '';

        if ( $this->combine_css )
            $html .= $this->combinedCssAndCleanArray();

        if ($this->combine_js)
            $html .= $this->combinedJsAndCleanArray(self::POS_HEAD);

        if ($html !== '')
            $output = preg_replace('/(<title\b[^>]*>|<\\/head\s*>)/is', "$html$1", $output, 1);

        parent::renderHead($output);
    }

    public function renderBodyBegin(&$output)
    {
        $html = '';

        if ($this->combine_js)
            $html .= $this->combinedJsAndCleanArray(self::POS_BEGIN);

        if ($html !== '')
            $output = preg_replace('/(<body\b[^>]*>)/is', "$1$html", $output, 1);

        parent::renderBodyBegin($output);
    }

    public function renderBodyEnd(&$output)
    {
        $html = '';

        if ($this->combine_js)
            $html .= $this->combinedJsAndCleanArray(self::POS_END);

        if ($html !== '')
            $output = preg_replace('/(<\\/body\s*>)/is', "$html$1", $output, 1);

        parent::renderBodyEnd($output);
    }

    private function combinedCssAndCleanArray()
    {
        if (empty($this->cssFiles))
            return '';

        $hash = md5(json_encode($this->cssFiles));

        $asset = new AssetCreator('', $hash, 'css');

        if (!$this->caching || $asset->needUpdate())
        {
            $content = '';

            foreach ($this->cssFiles as $url => $v)
                $content .= file_get_contents(AssetCreator::urlToPath($url));

            $css_min = CssMin::create();
            @$css_min->parse($content); /* FIX is_important */

            $asset->create($css_min->print->plain());
        }

        $this->cssFiles = [];

        return CHtml::cssFile($asset->urlPath())."\n";
    }

    private function combinedJsAndCleanArray($pos)
    {
        if (empty($this->scriptFiles[$pos]))
            return '';

        $hash = md5(json_encode($this->scriptFiles[$pos]));

        $asset = new AssetCreator('', $hash, 'js');

        if (!$this->caching || $asset->needUpdate())
        {
            $content = '';

            foreach ($this->scriptFiles[$pos] as $url => $v)
                $content .= file_get_contents(AssetCreator::urlToPath($url));

            if (($compiler = Yii::app()->getComponent('googleCompiler')) !== null)
                if ($res = $compiler->getCompiledByCode($content, 'SIMPLE_OPTIMIZATIONS', 'text'))
                    $content = $res;

            $asset->create($content);
        }

        $this->scriptFiles[$pos] = [];

        return CHtml::scriptFile($asset->urlPath())."\n";
    }
}