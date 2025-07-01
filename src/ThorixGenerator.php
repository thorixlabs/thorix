<?php

namespace Thorixlabs\Thorix;

use Symfony\Component\Yaml\Yaml;
use Twig\Loader\FilesystemLoader;
use Symfony\Component\Finder\Finder;
use League\CommonMark\MarkdownConverter;
use Twig\Environment as TwigEnvironment;
use Symfony\Component\Filesystem\Filesystem;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\FrontMatter\Output\RenderedContentWithFrontMatter;

class ThorixGenerator
{
    private string $sourceDir;
    private string $outputDir;
    private string $templatesDir;
    private Filesystem $filesystem;
    private TwigEnvironment $twig;
    private MarkdownConverter $markdownConverter;
    private array $config;
    private array $globalData;

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->sourceDir = $this->config['source_dir'];
        $this->outputDir = $this->config['output_dir'];
        $this->templatesDir = $this->config['templates_dir'];

        $this->filesystem = new Filesystem();
        $this->setupTwig();
        $this->setupMarkdown();
        $this->loadGlobalData();
    }

    private function getDefaultConfig(): array
    {
        return [
            'source_dir' => 'content',
            'output_dir' => 'dist',
            'templates_dir' => 'templates',
            'data_dir' => 'data',
            'assets_dir' => 'assets',
            'base_url' => '',
            'site_title' => 'My Static Site',
            'date_format' => 'Y-m-d H:i:s'
        ];
    }

    private function setupTwig(): void
    {
        $loader = new FilesystemLoader($this->templatesDir);
        $this->twig = new TwigEnvironment($loader, [
            'cache' => false,
            'debug' => true,
            'auto_reload' => true
        ]);

        $this->twig->addFunction(new \Twig\TwigFunction('asset', [$this, 'asset']));
        $this->twig->addFunction(new \Twig\TwigFunction('url', [$this, 'url']));
    }

    private function setupMarkdown(): void
    {
        $config = [];
        $environment = new Environment($config);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new FrontMatterExtension());

        $this->markdownConverter = new MarkdownConverter($environment);
    }

    private function loadGlobalData(): void
    {
        $this->globalData = $this->config;

        $dataDir = $this->config['data_dir'];
        if ($this->filesystem->exists($dataDir)) {
            $finder = new Finder();
            $finder->files()->in($dataDir)->name('*.yml')->name('*.yaml');

            foreach ($finder as $file) {
                $data = Yaml::parseFile($file->getRealPath());
                $key = $file->getBasename('.' . $file->getExtension());
                $this->globalData[$key] = $data;
            }
        }
    }

    public function build(): void
    {
        $this->cleanOutputDirectory();
        $this->copyAssets();
        $this->processContent();

        echo "Successfully generated site at: {$this->outputDir}\n";
    }

    private function cleanOutputDirectory(): void
    {
        if ($this->filesystem->exists($this->outputDir)) {
            $this->filesystem->remove($this->outputDir);
        }
        $this->filesystem->mkdir($this->outputDir);
    }

    private function copyAssets(): void
    {
        $assetsDir = $this->config['assets_dir'];
        if ($this->filesystem->exists($assetsDir)) {
            $this->filesystem->mirror($assetsDir, $this->outputDir . '/assets');
        }
    }

    private function processContent(): void
    {
        if (!$this->filesystem->exists($this->sourceDir)) {
            throw new \RuntimeException("The content directory '{$this->sourceDir}' does not exist");
        }

        $finder = new Finder();
        $finder->files()->in($this->sourceDir)->name('*.md');

        foreach ($finder as $file) {
            $this->processMarkdownFile($file);
        }
    }

    private function processMarkdownFile(\SplFileInfo $file): void
    {
        $content = file_get_contents($file->getRealPath());
        $result = $this->markdownConverter->convert($content);

        $frontMatter = [];
        $htmlContent = $result->getContent();

        if ($result instanceof RenderedContentWithFrontMatter) {
            $frontMatter = $result->getFrontMatter();
        }

        $pageData = array_merge([
            'title' => $this->getPageTitle($file),
            'content' => $htmlContent,
            'url' => $this->getPageUrl($file),
            'date' => date($this->config['date_format']),
            'template' => 'page.html.twig'
        ], $frontMatter);

        $templateData = array_merge($this->globalData, [
            'page' => $pageData,
            'content' => $htmlContent
        ]);

        $template = $pageData['template'];
        $renderedContent = $this->twig->render($template, $templateData);

        $outputPath = $this->getOutputPath($file);
        $this->filesystem->dumpFile($outputPath, $renderedContent);

        echo "Processed: {$file->getRelativePathname()} -> {$outputPath}\n";
    }

    private function getPageTitle(\SplFileInfo $file): string
    {
        return ucfirst(str_replace(['-', '_'], ' ', $file->getBasename('.md')));
    }

    private function getPageUrl(\SplFileInfo $file): string
    {
        $relativePath = $file->getRelativePathname();
        $url = '/' . str_replace('.md', '.html', $relativePath);
        return str_replace('\\', '/', $url);
    }

    private function getOutputPath(\SplFileInfo $file): string
    {
        $relativePath = $file->getRelativePathname();
        $outputPath = str_replace('.md', '.html', $relativePath);
        return $this->outputDir . '/' . str_replace('\\', '/', $outputPath);
    }

    public function asset(string $path): string
    {
        return rtrim($this->config['base_url'], '/') . '/assets/' . ltrim($path, '/');
    }

    public function url(string $path): string
    {
        return rtrim($this->config['base_url'], '/') . '/' . ltrim($path, '/');
    }

    public function serve(int $port = 8000): void
    {
        if (!$this->filesystem->exists($this->outputDir)) {
            $this->build();
        }

        $host = 'localhost';
        echo "Server started at http://{$host}:{$port}\n";
        echo "Press Ctrl+C to stop the server\n";

        exec("cd {$this->outputDir} && php -S {$host}:{$port}");
    }
}
