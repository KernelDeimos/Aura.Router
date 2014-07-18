<?php
/**
 *
 * This file is part of the Aura for PHP.
 *
 * @package Aura.Router
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 */
namespace Aura\Router;

use ArrayObject;
use Closure;

/**
 *
 * Represents an individual route with a name, path, params, values, etc.
 *
 * In general, you should never need to instantiate a Route directly. Use the
 * RouteFactory instead, or the Router.
 *
 * @package Aura.Router
 *
 * @property-read string $name The route name.
 *
 * @property-read string $path The route path.
 *
 * @property-read array $values Default values for params.
 *
 * @property-read array $params The matched params.
 *
 * @property-read string $regex The regular expression for the route.
 *
 * @property-read string $matches All params found during `isMatch()`.
 *
 * @property-read array $debug Debugging messages.
 *
 * @property-read callable $generate A callable for generating a link.
 *
 * @property-read string $wildcard The name of the wildcard param.
 *
 */
class Route extends AbstractSpec
{
    /**
     *
     * The route failed to match at isRoutableMatch().
     *
     * @const string
     *
     */
    const FAILED_ROUTABLE = 'FAILED_ROUTABLE';

    /**
     *
     * The route failed to match at isSecureMatch().
     *
     * @const string
     *
     */
    const FAILED_SECURE = 'FAILED_SECURE';

    /**
     *
     * The route failed to match at isRegexMatch().
     *
     * @const string
     *
     */
    const FAILED_REGEX = 'FAILED_REGEX';

    /**
     *
     * The route failed to match at isMethodMatch().
     *
     * @const string
     *
     */
    const FAILED_METHOD = 'FAILED_METHOD';

    /**
     *
     * The route failed to match at isAcceptMatch().
     *
     * @const string
     *
     */
    const FAILED_ACCEPT = 'FAILED_ACCEPT';

    /**
     *
     * The route failed to match at isServerMatch().
     *
     * @const string
     *
     */
    const FAILED_SERVER = 'FAILED_SERVER';

    /**
     *
     * The route failed to match at isCustomMatch().
     *
     * @const string
     *
     */
    const FAILED_CUSTOM = 'FAILED_CUSTOM';

    /**
     *
     * The name for this Route.
     *
     * @var string
     *
     */
    protected $name;

    /**
     *
     * The path for this Route with param tokens.
     *
     * @var string
     *
     */
    protected $path;

    /**
     *
     * Matched param values.
     *
     * @var array
     *
     */
    protected $params = array();

    /**
     *
     * The `$path` property converted to a regular expression, using the
     * `$tokens` subpatterns.
     *
     * @var string
     *
     */
    protected $regex;

    /**
     *
     * All params found during the `isMatch()` process, both from the path
     * tokens and from matched server values.
     *
     * @var array
     *
     * @see isMatch()
     *
     */
    protected $matches = array();

    /**
     *
     * Debugging information about why the route did not match.
     *
     * @var array
     *
     */
    protected $debug;

    /**
     *
     * The matching score for this route (+1 for each is*Match() that passes).
     *
     * @var int
     *
     */
    protected $score = 0;

    /**
     *
     * The failure code, if any, during matching.
     *
     * @var string
     *
     */
    protected $failure = null;

    /**
     *
     * Constructor.
     *
     * @param string $path The path for this Route with param token
     * placeholders.
     *
     * @param string $name The name for this route.
     *
     */
    public function __construct($path, $name = null)
    {
        $this->path = $path;
        $this->name = $name;
    }

    /**
     *
     * Magic read-only for all properties and spec keys.
     *
     * @param string $key The property to read from.
     *
     * @return mixed
     *
     */
    public function __get($key)
    {
        return $this->$key;
    }

    /**
     *
     * Magic isset() for all properties.
     *
     * @param string $key The property to check if isset().
     *
     * @return bool
     *
     */
    public function __isset($key)
    {
        return isset($this->$key);
    }

    /**
     *
     * Checks if a given path and server values are a match for this
     * Route.
     *
     * @param string $path The path to check against this Route.
     *
     * @param array $server A copy of $_SERVER so that this Route can check
     * against the server values.
     *
     * @return bool
     *
     */
    public function isMatch($path, array $server)
    {
        $this->debug = array();
        $this->params = array();
        $this->score = 0;
        $this->failure = null;
        if ($this->isFullMatch($path, $server)) {
            $this->setParams();
            return true;
        }
        return false;
    }

    protected function isFullMatch($path, array $server)
    {
        return $this->isRoutableMatch()
            && $this->isSecureMatch($server)
            && $this->isRegexMatch($path)
            && $this->isMethodMatch($server)
            && $this->isAcceptMatch($server)
            && $this->isServerMatch($server)
            && $this->isCustomMatch($server);
    }

    protected function pass()
    {
        $this->score ++;
        return true;
    }

    protected function fail($failure, $append = null)
    {
        $this->debug[] = $failure . $append;
        $this->failure = $failure;
        return false;
    }

    public function failedAccept()
    {
        return $this->failed == self::FAILED_ACCEPT;
    }

    public function failedMethod()
    {
        return $this->failed == self::FAILED_METHOD;
    }

    protected function isRoutableMatch()
    {
        if ($this->routable) {
            return $this->pass();
        }

        return $this->fail(self::FAILED_ROUTABLE);
    }

    /**
     *
     * Checks that the Route `$secure` matches the corresponding server values.
     *
     * @param array $server A copy of $_SERVER.
     *
     * @return bool True on a match, false if not.
     *
     */
    protected function isSecureMatch($server)
    {
        if ($this->secure === null) {
            return $this->pass();
        }

        if ($this->secure != $this->serverIsSecure($server)) {
            return $this->fail(self::FAILED_SECURE);
        }

        return $this->pass();
    }

    protected function serverIsSecure($server)
    {
        return (isset($server['HTTPS']) && $server['HTTPS'] == 'on')
            || (isset($server['SERVER_PORT']) && $server['SERVER_PORT'] == 443);
    }

    /**
     *
     * Checks that the path matches the Route regex.
     *
     * @param string $path The path to match against.
     *
     * @return bool True on a match, false if not.
     *
     */
    protected function isRegexMatch($path)
    {
        $this->setRegex();
        $regex = "#^{$this->regex}$#";
        $match = preg_match($regex, $path, $matches);
        if (! $match) {
            return $this->fail(self::FAILED_REGEX);
        }
        $this->matches = new ArrayObject($matches);
        return $this->pass();
    }

    /**
     *
     * Sets the regular expression for this Route.
     *
     * @return null
     *
     */
    protected function setRegex()
    {
        if ($this->regex) {
            return;
        }
        $this->regex = $this->path;
        $this->setRegexOptionalParams();
        $this->setRegexParams();
        $this->setRegexWildcard();
        $this->regex = '^' . $this->regex . '$';
    }

    /**
     *
     * Expands optional params in the regex from ``{/foo,bar,baz}` to
     * `(/{foo}(/{bar}(/{baz})?)?)?`.
     *
     * @return null
     *
     */
    protected function setRegexOptionalParams()
    {
        preg_match('#{/([a-z][a-zA-Z0-9_,]*)}#', $this->regex, $matches);
        if ($matches) {
            $repl = $this->getRegexOptionalParamsReplacement($matches[1]);
            $this->regex = str_replace($matches[0], $repl, $this->regex);
        }
    }

    protected function getRegexOptionalParamsReplacement($list)
    {
        $list = explode(',', $list);
        $head = $this->getRegexOptionalParamsReplacementHead($list);
        $tail = '';
        foreach ($list as $name) {
            $head .= "(/{{$name}}";
            $tail .= ')?';
        }

        return $head . $tail;
    }

    protected function getRegexOptionalParamsReplacementHead(&$list)
    {
        // if the optional set is the first part of the path, make sure there
        // is a leading slash in the replacement before the optional param.
        $head = '';
        if (substr($this->regex, 0, 2) == '{/') {
            $name = array_shift($list);
            $head = "/({{$name}})?";
        }
        return $head;
    }

    /**
     *
     * Expands param names in the regex to named subpatterns.
     *
     * @return null
     *
     */
    protected function setRegexParams()
    {
        $find = '#{([a-z][a-zA-Z0-9_]*)}#';
        preg_match_all($find, $this->regex, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $name = $match[1];
            $subpattern = $this->getSubpattern($name);
            $this->regex = str_replace("{{$name}}", $subpattern, $this->regex);
            if (! isset($this->values[$name])) {
                $this->values[$name] = null;
            }
        }
    }

    /**
     *
     * Returns a named subpattern for a param name.
     *
     * @param string $name The param name.
     *
     * @return string The named subpattern.
     *
     */
    protected function getSubpattern($name)
    {
        // is there a custom subpattern for the name?
        if (isset($this->tokens[$name])) {
            return "(?P<{$name}>{$this->tokens[$name]})";
        }

        // use a default subpattern
        return "(?P<{$name}>[^/]+)";
    }

    /**
     *
     * Adds a wildcard subpattern to the end of the regex.
     *
     * @return null
     *
     */
    protected function setRegexWildcard()
    {
        if (! $this->wildcard) {
            return;
        }

        $this->regex = rtrim($this->regex, '/')
                     . "(/(?P<{$this->wildcard}>.*))?";
    }

    protected function isMethodMatch($server)
    {
        if (! $this->method) {
            return $this->pass();
        }

        if (in_array($server['REQUEST_METHOD'], $this->method)) {
            return $this->pass();
        }

        return $this->fail(self::FAILED_METHOD);
    }

    protected function isAcceptMatch($server)
    {
        if (! $this->accept || ! isset($server['HTTP_ACCEPT'])) {
            return $this->pass();
        }

        $header = str_replace(' ', '', $server['HTTP_ACCEPT']);

        if ($this->isAcceptMatchHeader('*/*', $header)) {
            return $this->pass();
        }

        foreach ($this->accept as $type) {
            if ($this->isAcceptMatchHeader($type, $header)) {
                return $this->pass();
            }
        }

        return $this->fail(self::FAILED_ACCEPT);
    }

    protected function isAcceptMatchHeader($type, $header)
    {
        list($type, $subtype) = explode('/', $type);
        $type = preg_quote($type);
        $subtype = preg_quote($subtype);
        $regex = "#$type/($subtype|\*)(;q=(\d\.\d))?#";
        $found = preg_match($regex, $header, $matches);
        if (! $found) {
            return false;
        }
        return isset($matches[3]) && $matches[3] !== '0.0';
    }

    /**
     *
     * Checks that $_SERVER values match their related regular expressions.
     *
     * @param array $server A copy of $_SERVER.
     *
     * @return bool True if they all match, false if not.
     *
     */
    protected function isServerMatch($server)
    {
        foreach ($this->server as $name => $regex) {
            $matches = $this->isServerMatchRegex($server, $name, $regex);
            if (! $matches) {
                return $this->fail(self::FAILED_SERVER, " ($name)");
            }
            $this->matches[$name] = $matches[$name];
        }

        return $this->pass();
    }

    protected function isServerMatchRegex($server, $name, $regex)
    {
        $value = isset($server[$name])
               ? $server[$name]
               : '';
        $regex = "#(?P<{$name}>{$regex})#";
        preg_match($regex, $value, $matches);
        return $matches;
    }

    /**
     *
     * Checks that the custom Route `$is_match` callable returns true, given
     * the server values.
     *
     * @param array $server A copy of $_SERVER.
     *
     * @return bool True on a match, false if not.
     *
     */
    protected function isCustomMatch($server)
    {
        if (! $this->is_match) {
            return $this->pass();
        }

        // attempt the match
        $result = call_user_func($this->is_match, $server, $this->matches);

        // did it match?
        if (! $result) {
            return $this->fail(self::FAILED_CUSTOM);
        }

        return $this->pass();
    }

    /**
     *
     * Sets the route params from the matched values.
     *
     * @return null
     *
     */
    protected function setParams()
    {
        $this->params = $this->values;
        $this->setParamsWithMatches();
        $this->setParamsWithWildcard();

    }

    protected function setParamsWithMatches()
    {
        // populate the path matches into the route values. if the path match
        // is exactly an empty string, treat it as missing/unset. (this is
        // to support optional ".format" param values.)
        foreach ($this->matches as $key => $val) {
            if (is_string($key) && $val !== '') {
                $this->params[$key] = rawurldecode($val);
            }
        }
    }

    protected function setParamsWithWildcard()
    {
        if (! $this->wildcard) {
            return;
        }

        if (empty($this->params[$this->wildcard])) {
            $this->params[$this->wildcard] = array();
            return;
        }

        $this->params[$this->wildcard] = array_map(
            'rawurldecode',
            explode('/', $this->params[$this->wildcard])
        );
    }
}
