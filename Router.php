<?php
declare(strict_types=1);

namespace Webiik\Router;

class Router
{
    /**
     * @var string
     */
    private $baseURI = '';

    /**
     * @var array
     */
    private $routes = [];

    /**
     * Languages of added routes
     * @var array
     */
    private $routeLangs = [];

    /**
     * @var string
     */
    private $defaultLang = 'en';

    /**
     * @var bool
     */
    private $defaultLangInURI = false;

    /**
     * HTTP code, it is changed when route matches
     * Possible codes: 200, 404, 405
     * @var int
     */
    private $httpCode = 404;

    /**
     * After calling getURL or getURI, this is filled with array of missing route parameters:
     * [int parameterPosition => string parameterRegex], ...
     * @var array
     */
    private $missingParameters = [];

    /**
     * Set the base directory of your index.php file relatively to web-server root
     * @param string $baseURI
     */
    public function setBaseURI(string $baseURI): void
    {
        if (!$baseURI || $baseURI == '/') {
            $this->baseURI = '';
        } else {
            $this->baseURI = '/' . trim($baseURI, '/');
        }
    }

    /**
     * Set default route language
     * @param string $defaultLang
     */
    public function setDefaultLang(string $defaultLang): void
    {
        $this->defaultLang = $defaultLang;
    }

    /**
     * Determine if default language is part of URI e.g. /en/
     * @param bool $defaultLangInURI
     */
    public function setDefaultLangInURI(bool $defaultLangInURI): void
    {
        $this->defaultLangInURI = $defaultLangInURI;
    }

    /**
     * Return base URL of the app
     * @return string
     */
    public function getBaseURL(): string
    {
        return $this->getServer() . $this->baseURI;
    }

    /**
     * @param array $methods e.g. ['get' , 'post']
     * @param string $route e.g. '/about'
     * @param string $controller 'className:MethodName' or 'className'
     * @param string $name (optional) It should be unique e.g. 'about'
     * @param string $lang (optional) e.g. 'es' When omitted default lang is used instead
     * @return NewRoute
     */
    public function addRoute(
        array $methods,
        string $route,
        string $controller,
        string $name = '',
        string $lang = ''
    ): NewRoute {
        // Get route lang
        $lang = $lang ? $lang : $this->defaultLang;
        $langLowerCase = strtolower($lang);

        // Get route regex lang prefix
        $langPrefix = '';
        if ($this->defaultLangInURI || $lang != $this->defaultLang) {
            $langPrefix = '/' . $lang;
        }

        // Prepare final route regex
        $route = $langPrefix . $this->formatRouteRegex($route);

        // Create new Route
        $route = new NewRoute($methods, $route, $controller, $name, $lang);

        // Add created route to routes array
        if ($name) {
            $this->routes[$langLowerCase][$name] = $route;
        } else {
            $this->routes[$langLowerCase][] = $route;
        }

        // Add lang to route languages
        $this->routeLangs[$langLowerCase] = true;

        return $route;
    }

    /**
     * Match current request URI against defined routes.
     * If route doesn't exist return false.
     * @return Route
     */
    public function match(): Route
    {
        $requestURI = $this->getBaseRequestURI();

        $this->slashRedirect($requestURI);

        // Try to determine language from URI
        $requestURILang = strtolower($this->getLangFromRequestURI($requestURI));

        // If there is no language in URI, use default language
        $lang = $requestURILang ? $requestURILang : $this->defaultLang;

        $requestMethod = $this->getMethod();

        foreach ($this->routes[$lang] as $route) {
            /** @var NewRoute $route */
            preg_match($route->regex, $requestURI, $match);
            if ($match) {
                // Determine HTTP code by HTTP method
                $this->httpCode = 405;
                foreach ($route->httpMethods as $httpMethod) {
                    if ($requestMethod == strtolower($httpMethod)) {
                        $this->httpCode = 200;
                        break;
                    }
                }

                // Don't create Route if HTTP code is not 200
                if ($this->httpCode != 200) {
                    break;
                }

                // Prepare route parameters
                unset($match[0]);

                // Get route regex fot all lang version
                $regex[$route->lang] = $route->regex;
                foreach ($this->routeLangs as $lang => $val) {
                    if (isset($this->routes[$lang][$route->name])) {
                        $regex[$lang] = $this->routes[$lang][$route->name]->regex;
                    }
                }

                // Matching route found, stop searching
                break;
            }
        }

        // Create route
        if (isset($regex)) {
            // Route found (200, 405)
            $route = new Route($route->controller, $route->name, $route->lang, $route->middleware, $match);
        } else {
            // Route not found (404)
            $route = new Route('', '', '', [], []);
        }

        return $route;
    }

    /**
     * Get URI by route name
     * @param string $routeName
     * @param array $parameters Route parameters
     * @param string $lang Route language
     * @return string
     */
    public function getURI(string $routeName, array $parameters = [], string $lang = ''): string
    {
        // Default return value
        $URI = '';

        // Determine lang
        $lang = $lang ? $lang : $this->defaultLang;

        // Check if route exists
        if (isset($this->routes[$lang], $this->routes[$lang][$routeName])) {

            // Check if all parameters were provided...

            // Reset missing route parameters before every getURI call
            $this->missingParameters = [];

            // Position of parameter in $parameters array
            $paramPos = 0;

            // Replace regex groups(parameters) with values
            $URI = preg_replace_callback('~(\((\?\<(.+?)\>+)?.+?\)(\?)?)/~',function ($match) use (&$parameters, &$paramPos) {

                // Set default URI parameter replacement value
                $replacement = '';

                // Determine if URI parameter is required
                $isParamRequired = isset($match[4]) && $match[4] ? false : true;

                // Determine parameter key by parameter type (named : anonymous)
                $paramKey = isset($match[3]) && $match[3] ? $match[3] : $paramPos;

                // Update URI parameter with value from $parameters
                if (isset($parameters[$paramKey]) && preg_match('~' . $match[1] . '~', $parameters[$paramKey])) {
                    $replacement = $parameters[$paramKey];
                } elseif ($isParamRequired) {
                    $this->missingParameters[$paramKey] = $match[1];
                }

                // In preg_replace_callback we replace slash after regex group.
                // We have to add this slash back. But in case of empty replacement,
                // we don't add it back to prevent double slashes '//'.
                $slash = $replacement ? '/' : '';

                // Update index position of $parameters array
                $paramPos++;

                return $replacement . $slash;
            }, $this->routes[$lang][$routeName]->regex);

            // Never return URI when required parameters are missing
            if ($this->missingParameters) {
                $URI = '';
            }

            // Finalize URI (remove regex special chars to get clean URI)
            if ($URI) {
                $URI = $this->baseURI . preg_replace('/[~\^\?\$]|i$/', '', $URI);
                $URI = substr($URI, strlen($this->baseURI));
            }
        }

        return $URI;
    }

    /**
     * Get URL by route name
     * @param string $routeName
     * @param array $parameters
     * @param string $lang
     * @return string
     */
    public function getURL(string $routeName, array $parameters = [], string $lang = ''): string
    {
        $URL = '';
        if ($URI = $this->getURI($routeName, $parameters, $lang)) {
            $URI = $URI == '/' ? '' : $URI;
            $URL = $this->getBaseURL() . $URI;
        }
        return $URL;
    }

    /**
     * Get missing parameters after calling getURI or getURL
     * [int parameterPosition => string parameterRegex], ...
     * e.g. ['1' => '([a-z]*)']
     * @return array
     */
    public function getMissingParameters(): array
    {
        return $this->missingParameters;
    }

    /**
     * Get route regex parameters
     * [int parameterPosition => string parameterRegex], ...
     * e.g. ['0' => '(?<name>[a-z]*)?', '1' => '([a-z]*)']
     * @param string $routeName
     * @param string $lang
     * @return array|false
     */
    public function getRegexParameters(string $routeName, string $lang = '')
    {
        // Determine lang
        $lang = $lang ? $lang : $this->defaultLang;

        if (isset($this->routes[$lang], $this->routes[$lang][$routeName])) {
            preg_match_all('~\(.+?\)\??~', $this->routes[$lang][$routeName]->regex, $matches);
            return $matches[0];
        }

        return false;
    }

    /**
     * Get http code of the result of last call of method match
     *
     * Possible values:
     * 404 - Not Found
     * 405 - Method Not Allowed
     * 200 - OK
     *
     * @return int
     */
    public function getHttpCode(): int
    {
        return $this->httpCode;
    }

    /**
     * @return string
     */
    private function getMethod(): string
    {
        return strtolower($_SERVER['REQUEST_METHOD']);
    }

    /**
     * Get current server scheme and host e.g. https://127.0.0.1
     * @return string
     */
    private function getServer(): string
    {
        return $this->getScheme() . '://' . $this->getHost();
    }

    /**
     * Get current server scheme
     * @return string
     */
    private function getScheme(): string
    {
        $scheme = 'http';
        if (isset($_SERVER['HTTPS']) && !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') {
            $scheme = 'https';
        }
        return $scheme;
    }

    /**
     * Get current server name or address
     * @return string
     */
    private function getHost(): string
    {
        $host = $_SERVER['SERVER_ADDR'];
        if (isset($_SERVER['SERVER_NAME']) && $_SERVER['SERVER_NAME']) {
            $host = $_SERVER['SERVER_NAME'];
        } elseif (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST']) {
            $host = $_SERVER['HTTP_HOST'];
        }
        return $host;
    }

    /**
     * Get request URI without query string
     * @return string
     */
    private function getRequestURI(): string
    {
        preg_match('/^[^\?]+/', $_SERVER['REQUEST_URI'], $match);
        return isset($match[0]) ? (string)$match[0] : '';
    }

    /**
     * Get request URI without query string and base URL
     * @return string
     * @throws \Exception
     */
    private function getBaseRequestURI(): string
    {
        if (!$this->baseURI || $this->baseURI == '/') {
            $baseRequestURI = $this->getRequestURI();
        } else {
            $baseRequestURI = substr($this->getRequestURI(), strlen($this->baseURI));
            if (is_bool($baseRequestURI)) {
                throw new \Exception('Class: Router, Invalid base URI set by method setBaseURI()');
            }
        }
        return $baseRequestURI;
    }

    /**
     * Get two letter lang code from URI
     * @param string $URI
     * @return string
     */
    private function getLangFromRequestURI(string $URI)
    {
        preg_match('~^/([a-z]{2})/~i', $URI, $lang);
        return isset($lang[1], $this->routeLangs[$lang[1]]) ? $lang[1] : '';
    }

    /**
     * @param string $regex
     * @return string
     */
    private function formatRouteRegex(string $regex): string
    {
        // Trim slashes to allow users to write route regex in more ways:
        // e.g. /about/, about, /about, about/
        $regex = trim($regex, '/');

        // Format route regex to have always one slash at the beginning and at the end e.g. /about/
        // If regex is empty it means it's a home page, then add only one slash /
        $regex = $regex ? '/' . $regex . '/' : '/';

        // Find optional route parameters and if slash before parameter is not optional, make it optional.
        // e.g. Without this fix route regex /([a-z]+)?/reviews/ would not work correctly for URI /reviews/
        $regex = preg_replace('~(/)(\(.+\)\?)~', '/?$2', $regex);

        return (string)$regex;
    }

    /**
     * Redirect the URL without or with many slashes at the and to the URL with one slash at the end
     * http://googlewebmastercentral.blogspot.cz/2010/04/to-slash-or-not-to-slash.html
     */
    private function slashRedirect(string $requestURI): void
    {
        if (substr($requestURI, -1) != '/' || substr($requestURI, -2) == '//') {
            header('HTTP/1.1 301 Moved Permanently');
            header('Location: ' . $this->baseURI . rtrim($requestURI, '/') . '/' . $this->getUrlQuery($_GET));
            exit();
        }
    }

    /**
     * Prepare URL query string from array
     * @param array $array
     * @return string
     */
    private function getUrlQuery(array $array): string
    {
        $queryString = http_build_query($array);
        return $queryString ? '?' . $queryString : $queryString;
    }
}
