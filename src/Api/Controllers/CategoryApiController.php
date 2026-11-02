<?php

declare(strict_types=1);

namespace WinCart\Api\Controllers;

use PDO;
use WinCart\Catalog\Models\Category;
use WinCart\Catalog\Repositories\CategoryRepositoryInterface;
use WinCart\Catalog\Repositories\PdoCategoryRepository;
use WinCart\Http\ProblemDetails;
use WinCart\Tests\E2E\Support\HttpResponse;

final class CategoryApiController
{
    private CategoryRepositoryInterface $categoryRepository;

    public function __construct(private PDO $pdo)
    {
        $this->categoryRepository = new PdoCategoryRepository($this->pdo);
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    public function list(array $queryParams = []): HttpResponse
    {
        $isTree = (isset($queryParams['format']) && $queryParams['format'] === 'tree')
            || (isset($queryParams['tree']) && ($queryParams['tree'] === '1' || $queryParams['tree'] === 'true'));

        if ($isTree) {
            $categories = $this->categoryRepository->findTree();
        } else {
            $categories = $this->categoryRepository->findAll();
        }

        $data = array_map(fn(Category $c) => $c->toArray(), $categories);
        return new HttpResponse(
            200,
            (string)json_encode(['data' => $data, 'total' => count($data)], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            ['Content-Type' => ['application/json; charset=UTF-8']]
        );
    }

    public function show(string $idOrSlug): HttpResponse
    {
        $category = null;
        if (ctype_digit($idOrSlug)) {
            $category = $this->categoryRepository->findById((int)$idOrSlug);
        } else {
            $category = $this->categoryRepository->findBySlug($idOrSlug);
        }

        if ($category === null) {
            return ProblemDetails::create(404, 'Not Found', "Category '{$idOrSlug}' not found");
        }

        return new HttpResponse(
            200,
            (string)json_encode($category->toArray(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            ['Content-Type' => ['application/json; charset=UTF-8']]
        );
    }
}
