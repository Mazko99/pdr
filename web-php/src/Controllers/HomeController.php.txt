<?php
declare(strict_types=1);

namespace App\Controllers;

final class HomeController
{
  public function index(): void
  {
    $title = 'Підготовка до ЄДКІ — Landing';
    $apiBase = getenv('API_BASE_URL') ?: '/api';
    require __DIR__ . '/../Views/home.php';
  }
}
