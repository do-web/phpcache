<?php
namespace PHP\Cache;
use Symfony\Component\Yaml\Yaml;

/**
 * Class Cache
 * @package PHP\Cache
 */
class Cache {

    /**
     * @var string
     */
    const CACHE_DATA_EXT = '.data.cache';

    /**
     * @var string
     */
    const CACHE_HTML_EXT = '.content.cache';

    /**
     * @var string
     */
    protected $cachePath = '';

    /**
     * @var string
     */
    protected $cacheKey = '';

    /**
     * @var bool
     */
    protected $cachingActive = false;

    /**
     * @var array
     */
    protected $config = [];

    /**
     * @param $buffer
     * @param $phase
     * @return mixed
     */
    protected function cleanUp($buffer, $phase) {
        $buffer = $this->stop($buffer);

        if($this->config['gzip']) {
            $buffer = gzencode($buffer, 9);
            header('Content-Encoding: gzip');
            header( 'Content-Length: ' . strlen($buffer));
        }

        return $buffer;
    }

    /**
     * @param $array
     * @return bool
     */
    protected function sortArray(&$array) {
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->sortArray($value);
            }
        }
        return ksort($array);
    }

    /**
     * @return string
     */
    protected function getUri() {
        $query = $_GET;
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')  || $_SERVER['SERVER_PORT'] == 443;
        $protocol = $isHttps ? 'https://' : 'http://';
        $urlParams = parse_url($protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
        $this->sortArray($query);
        return $urlParams['scheme'] . '://' . $urlParams['host'] . $urlParams['path'] . '?' . http_build_query($query);
    }

    /**
     * @return string
     */
    protected function generateCacheKey() {
        return md5($this->getUri());
    }

    /**
     * @return string
     */
    public function getHtmlCacheFilename() {
        return $this->cachePath . DIRECTORY_SEPARATOR . $this->cacheKey . self::CACHE_HTML_EXT;
    }

    /**
     * @return string
     */
    public function getDataCacheFilename() {
        return $this->cachePath . DIRECTORY_SEPARATOR . $this->cacheKey . self::CACHE_DATA_EXT;
    }

    /**
     * @return bool
     */
    protected function isExcluded() {
        $url = $this->getUri();
        if(is_array($this->config['exclude'])) {
            foreach($this->config['exclude'] as $rx) {
                if(preg_match($rx, $url)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @return bool
     */
    protected function isAllowedMethod() {
        return ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'HEAD');
    }

    /**
     *
     */
    public function start() {
        try {
            $this->config = Yaml::parse(file_get_contents('../phpcache.yaml'));
            $this->cachePath = realpath($this->config['cache_dir']);

            // Create cache folder
            if(!$this->cachePath) {
                mkdir($this->config['cache_dir'], $this->config['file_mode'], true);
                $this->cachePath = realpath($this->config['cache_dir']);
            }

            $isXhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

            if ($this->isExcluded() === false && $this->isAllowedMethod() === true &&
                ($isXhr === false || ($this->config['xhr'] === true && $isXhr === true))) {

                $this->cachingActive = true;
                $this->cacheKey = $this->generateCacheKey();

                if(is_file($this->getHtmlCacheFilename()) && is_file($this->getDataCacheFilename())) {
                    if((time() - filemtime($this->getHtmlCacheFilename())) > intval($this->config['lifetime'])) {
                        unlink($this->getHtmlCacheFilename());
                        unlink($this->getDataCacheFilename());
                    } else {

                        $data = unserialize(file_get_contents($this->getDataCacheFilename()));
                        $this->sendCachedHeaders($data);

                        $content = file_get_contents($this->getHtmlCacheFilename());

                        if($this->config['gzip']) {
                            $content = gzencode($content, 9);
                            header('Content-Encoding: gzip');
                            header( 'Content-Length: ' . strlen($content));
                        } else {
                            header( 'Content-Length: ' . strlen($content));
                        }

                        echo $content;
                        exit();
                    }
                }
                ob_start([$this, 'cleanUp'], 0, PHP_OUTPUT_HANDLER_STDFLAGS ^ PHP_OUTPUT_HANDLER_REMOVABLE);
            }
        } catch (ParseException $e) {
            error_log("Unable to parse the phpcache config YAML string: %s", $e->getMessage());
        }
    }

    /**
     * @param $data
     */
    protected function sendCachedHeaders($data) {
        if(isset($data)) {
            if(is_array($data['headers'])) {
                foreach($data['headers'] as $header) {
                    header($header);
                }
            }
            if(!empty($data['responseCode'])) {
                http_response_code($data['responseCode']);
            }
        }
        header('X-Phpcache: hit');
    }

    /**
     * @return bool
     */
    protected function clear() {
        foreach (glob($this->cachePath . DIRECTORY_SEPARATOR . '*' . self::CACHE_HTML_EXT) as $htmlFile) {
            unlink($htmlFile);
        }
        foreach (glob($this->cachePath . DIRECTORY_SEPARATOR . '*' . self::CACHE_DATA_EXT) as $dataFile) {
            unlink($dataFile);
        }
        return true;
    }

    /**
     * @param $content
     */
    protected function save($content) {
        file_put_contents($this->getHtmlCacheFilename(), $content);

        $headers = headers_list();
        $removeHeaders = ['X-Powered-By', 'Set-Cookie'];

        foreach($headers as $k => $header) {
            foreach($removeHeaders as $remove) {
                if(preg_match('/' . preg_quote($remove, '/') . ':/is', $header)) {
                    unset($headers[$k]);
                }
            }
        }

        $data = [
            'headers' => $headers,
            'responseCode' => http_response_code()
        ];

        file_put_contents($this->getDataCacheFilename(), serialize($data));
    }

    /**
     * @param $content
     * @return mixed
     */
    protected function stop($content) {
        if($this->cachingActive) {
            $this->save($content);
        }
        return $content;
    }
}