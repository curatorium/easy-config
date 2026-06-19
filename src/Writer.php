<?php

namespace Curatorium\EasyConfig;

use Twig\Environment as Twig;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Extension\DebugExtension;
use Twig\Lexer;
use Twig\Loader\FilesystemLoader as Loader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;

class Writer
{
    private Twig $twig;

    private string $templates;

    /** @var array<string, bool> a list of visited files. */
    private array $visited;

    public function __construct(string $templates)
    {
        $this->templates = rtrim($templates, '/');

        $twig = new Twig(new Loader([$this->templates, __DIR__.'/../tpl/']), [
            'autoescape' => false,
            'debug' => true,
            'cache' => false,
            'strict_variables' => true,
        ]);
        $twig->addExtension(new DebugExtension());
        $twig->setLexer(new Lexer($twig, ['tag_variable' => ['${', '}']]));

        $twig->addFilter(new TwigFilter('filter', 'array_filter'));

        // Read a runtime environment variable, with an optional fallback when unset.
        $twig->addFunction(new TwigFunction('env', fn ($name, $default = null) => getenv($name) !== false ? getenv($name) : $default));

        $twig->addFilter(new TwigFilter('b64', fn ($data) => base64_encode(!is_scalar($data) ? json_encode($data) : $data)));
        $twig->addFilter(new TwigFilter('json', fn ($object) => json_encode($object, JSON_UNESCAPED_SLASHES)));
        $twig->addFilter(new TwigFilter('ksort', function ($list) { ksort($list); return $list; }));
        $twig->addFilter(new TwigFilter('url', fn ($url, $part) => parse_url($url, $part)));
        $twig->addFilter(new TwigFilter('yaml', fn ($v) => trim(yaml_emit($v), "-\n.")));

        $twig->addTest(new TwigTest('bool', fn($v) => is_bool($v)));
        $twig->addTest(new TwigTest('int', fn($v) => is_int($v)));
        $twig->addTest(new TwigTest('numeric', fn($v) => is_numeric($v)));
        $twig->addTest(new TwigTest('float', fn($v) => is_float($v)));
        $twig->addTest(new TwigTest('file', fn($v) => is_file($v)));
        $twig->addTest(new TwigTest('directory', fn($v) => is_dir($v)));
        $twig->addTest(new TwigTest('path', fn($v) => file_exists($v)));
        $twig->addTest(new TwigTest('scalar', fn($v) => is_scalar($v)));
        $twig->addTest(new TwigTest('string', fn($v) => is_string($v)));

        $this->twig = $twig;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function renderSource(string $source, array $params): string
    {
        return $this->twig->createTemplate($source)->render($params);
    }

    public function renderType(string $type, array $params, string $outFile, bool $resolve = false): void
    {
        $templates = glob($this->templates.'/'.$type.'.*.twig');
        foreach ($templates as $template) {
            $ext = pathinfo(pathinfo($template, PATHINFO_FILENAME), PATHINFO_EXTENSION);
            $params['tags']['ext'] = $ext;
            $this->renderTemplate($type.'.'.$ext.'.twig', $params, $outFile, $resolve);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public function renderTemplate(string $template, array $params, string $outFile, bool $resolve = false): void
    {
        if ($resolve) {
            $this->renderTemplate('ToYAML.yml.twig', $params, 'php://stdout');

            return;
        }

        try {
            $output = $this->twig->load($template)->render($params);
            $outFile = trim($this->renderSource($outFile, $params));
        } catch (LoaderError $e) {
            throw $e;
        } catch (SyntaxError $e) {
            throw $e;
        } catch (RuntimeError $e) {
            $type = $params['tags']['type'];
            $e->appendMessage("\n\n".yaml_emit([
                'tags' => $params['tags'],
                'params' => $params['own'],
                ':'.$type => $params[':'.$type],
                ':Default' => $params[':Default'],
            ]));
            throw $e;
        }

        if (!str_starts_with($outFile, 'php://') and !is_dir(dirname($outFile))) {
            mkdir(dirname($outFile), 0774, true);
        }
        if (!isset($this->visited[$outFile]) and file_exists($outFile)) {
            unlink($outFile);
        }
        $this->visited[$outFile] = true;
        file_put_contents($outFile, $output, FILE_APPEND);
    }
}
