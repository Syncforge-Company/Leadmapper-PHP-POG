<?php
declare(strict_types=1);

// PHP BACKEND: configuracao, endpoints internos e integracao com SerpAPI.

loadEnvFile(__DIR__ . DIRECTORY_SEPARATOR . ".env");

$serpApiKey = getenv("SERP_API_KEY") ?: "";
$manausCoords = getenv("MANAUS_COORDS") ?: "-3.1190,-60.0217";

$categories = [
  ["value" => "all", "label" => "Todos os segmentos", "placeType" => ""],
  [
    "value" => "restaurant",
    "label" => "Restaurantes",
    "placeType" => "restaurant",
  ],
  [
    "value" => "doctor",
    "label" => "Clínicas e médicos",
    "placeType" => "doctor",
  ],
  [
    "value" => "lawyer",
    "label" => "Escritorios juridicos",
    "placeType" => "lawyer",
  ],
  ["value" => "pharmacy", "label" => "Farmacias", "placeType" => "pharmacy"],
  ["value" => "car_repair", "label" => "Oficinas", "placeType" => "car_repair"],
  [
    "value" => "clothing_store",
    "label" => "Lojas de roupa",
    "placeType" => "clothing_store",
  ],
  [
    "value" => "lodging",
    "label" => "Hoteis e pousadas",
    "placeType" => "lodging",
  ],
  [
    "value" => "beauty_salon",
    "label" => "Barbearias e saloes",
    "placeType" => "beauty_salon",
  ],
  [
    "value" => "accounting",
    "label" => "Contabilidade",
    "placeType" => "accounting",
  ],
  ["value" => "pet_store", "label" => "Pet shop", "placeType" => "pet_store"],
  ["value" => "gym", "label" => "Academias", "placeType" => "gym"],
  [
    "value" => "dentist",
    "label" => "Clínicas odontológicas",
    "placeType" => "dentist",
  ],
  [
    "value" => "supermarket",
    "label" => "Supermercados",
    "placeType" => "supermarket",
  ],
];

if (isset($_GET["api"])) {
  header("Content-Type: application/json; charset=utf-8");
  header("Access-Control-Allow-Origin: *");

  try {
    if ($_GET["api"] === "config") {
      sendJson([
        "googleMapsConfigured" => $serpApiKey !== "",
        "categories" => array_map(
          static fn(array $item): array => [
            "value" => $item["value"],
            "label" => $item["label"],
          ],
          $categories,
        ),
      ]);
    }

    if ($_GET["api"] === "leads") {
      if ($serpApiKey === "") {
        http_response_code(500);
        sendJson([
          "error" =>
            "Defina SERP_API_KEY no ambiente do PHP antes de buscar leads.",
        ]);
      }

      $query = trim((string) ($_GET["query"] ?? ""));
      $type = (string) ($_GET["type"] ?? "all");
      $radius = max(1000, min(20000, (int) ($_GET["radius"] ?? 5000)));
      error_log(
        sprintf(
          '[LeadMapper] Buscando leads query="%s" type="%s" radius=%d',
          $query,
          $type,
          $radius,
        ),
      );

      $leads = fetchSerpApiLeads(
        $serpApiKey,
        $manausCoords,
        $categories,
        $query,
        $type,
        $radius,
      );
      error_log(
        sprintf('[LeadMapper] Busca finalizada com %d leads', count($leads)),
      );
      sendJson(["leads" => $leads, "source" => "serpapi"]);
    }

    if ($_GET["api"] === "proposal") {
      $payload = json_decode(
        file_get_contents("php://input") ?: "{}",
        true,
        512,
        JSON_THROW_ON_ERROR,
      );
      if (!is_array($payload) || empty($payload["lead"]["name"])) {
        http_response_code(400);
        sendJson(["error" => "Lead invalido para gerar proposta."]);
      }

      $brand = (string) ($payload["brand"] ?? "syncforge");
      sendJson([
        "proposal" => buildProposal($payload["lead"], $brand),
        "brand" => $brand,
      ]);
    }

    http_response_code(404);
    sendJson(["error" => "Endpoint nao encontrado."]);
  } catch (Throwable $e) {
    http_response_code(500);
    sendJson(["error" => $e->getMessage()]);
  }
}

function sendJson(array $payload): void
{
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit();
}

function fixMojibake(string $text): string
{
  return strtr($text, [
    "Ã¡" => "á",
    "Ã " => "à",
    "Ã¢" => "â",
    "Ã£" => "ã",
    "Ã¤" => "ä",
    "Ã©" => "é",
    "Ã¨" => "è",
    "Ãª" => "ê",
    "Ã­" => "í",
    "Ã¬" => "ì",
    "Ã³" => "ó",
    "Ã²" => "ò",
    "Ã´" => "ô",
    "Ãµ" => "õ",
    "Ãº" => "ú",
    "Ã§" => "ç",
    "Ã‰" => "É",
    "Ã“" => "Ó",
    "Ã‡" => "Ç",
    "Â·" => "·",
    "Â" => "",
  ]);
}

function loadEnvFile(string $filePath): void
{
  if (!is_file($filePath) || !is_readable($filePath)) {
    return;
  }

  $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if ($lines === false) {
    return;
  }

  foreach ($lines as $line) {
    $trimmed = trim($line);
    if ($trimmed === "" || str_starts_with($trimmed, "#")) {
      continue;
    }

    $separator = strpos($trimmed, "=");
    if ($separator === false) {
      continue;
    }

    $key = trim(substr($trimmed, 0, $separator));
    $value = trim(substr($trimmed, $separator + 1));
    $value = trim($value, "\"'");

    if ($key === "" || getenv($key) !== false) {
      continue;
    }

    putenv($key . "=" . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
  }
}

function fetchSerpApiLeads(
  string $serpApiKey,
  string $manausCoords,
  array $categories,
  string $query,
  string $type,
  int $radius,
): array {
  $category = findCategory($categories, $type);
  $searchQuery = buildSearchQuery($query, $category);
  error_log(sprintf('[LeadMapper] Query enviada para SerpAPI: %s', $searchQuery));

  $url =
    "https://serpapi.com/search.json?" .
    http_build_query([
      "engine" => "google_maps",
      "q" => $searchQuery,
      "ll" => "@" . $manausCoords . ",13z",
      "type" => "search",
      "api_key" => $serpApiKey,
    ]);

  $payload = httpGetJson($url);
  $results = $payload["local_results"] ?? [];
  if ($results === [] && !empty($payload["place_results"])) {
    $results = [$payload["place_results"]];
    error_log('[LeadMapper] SerpAPI retornou place_results para busca exata.');
  }

  $leads = [];

  foreach ($results as $place) {
    $leads[] = mapSerpApiPlaceToLead($place, $category, $categories);
  }

  usort($leads, fn($a, $b) => $b["score"] <=> $a["score"]);
  error_log(
    sprintf(
      '[LeadMapper] SerpAPI retornou %d resultados brutos; %d leads apos ordenacao',
      count($results),
      count($leads),
    ),
  );

  return array_slice($leads, 0, 20);
}

function mapSerpApiPlaceToLead(array $place, array $fallbackCategory, array $categories): array
{
  $website = (string) ($place["website"] ?? "");
  $phone = (string) ($place["phone"] ?? "");
  $rawType = $place["type"] ?? $fallbackCategory["value"];
  $categoryName = is_array($rawType)
    ? normalizePlaceType((string) ($rawType[0] ?? $fallbackCategory["value"]))
    : normalizePlaceType((string) $rawType);
  $categoryLabel = resolveCategoryLabel(
    $categoryName,
    $fallbackCategory,
    $categories,
  );

  $score = 0;
  if ($website === "") {
    $score += 50;
  }
  if ($phone !== "") {
    $score += 20;
  }
  if ((float) ($place["rating"] ?? 0) >= 4.3) {
    $score += 20;
  }
  if ((int) ($place["reviews"] ?? 0) >= 20) {
    $score += 10;
  }

  return [
    "id" => md5((string) ($place["place_id"] ?? $place["title"] ?? uniqid("", true))),
    "name" => (string) ($place["title"] ?? "Empresa"),
    "category" => $categoryName,
    "categoryLabel" => $categoryLabel,
    "phone" => $phone,
    "website" => $website,
    "address" => (string) ($place["address"] ?? ""),
    "rating" => (string) ($place["rating"] ?? ""),
    "reviews" => (int) ($place["reviews"] ?? 0),
    "intent" => inferIntent($categoryName, $categoryLabel),
    "salesAngle" => inferSalesAngle($categoryName, $website !== ""),
    "score" => $score,
    "hasWebsite" => $website !== "",
  ];
}

function normalizePlaceType(string $value): string
{
  return str_replace(" ", "_", mb_strtolower(trim($value)));
}

function resolveCategoryLabel(
  string $primaryType,
  array $fallbackCategory,
  array $categories,
): string {
  foreach ($categories as $category) {
    if (($category["placeType"] ?? "") === $primaryType) {
      return (string) $category["label"];
    }
  }

  return (string) $fallbackCategory["label"];
}

function inferIntent(string $type, string $categoryLabel): string
{
  $map = [
    "restaurant" => "Atrair reservas, pedidos e mais fluxo no horario de pico.",
    "doctor" =>
      "Converter pesquisas locais em agendamentos e transmitir confianca.",
    "dentist" => "Gerar consultas particulares e destacar especialidades.",
    "lawyer" => "Captar contatos qualificados e reforcar autoridade.",
    "pharmacy" => "Estimular pedidos rapidos e localizacao facil da loja.",
    "car_repair" => "Receber orcamentos e organizar atendimento de servicos.",
    "clothing_store" => "Aumentar visitas e campanhas de colecoes sazonais.",
    "lodging" => "Receber reservas diretas sem depender apenas de OTAs.",
    "beauty_salon" => "Facilitar agendamento e aumentar frequencia de retorno.",
    "accounting" =>
      "Captar empresas locais interessadas em suporte recorrente.",
    "pet_store" => "Trazer pedidos, banhos e servicos recorrentes.",
    "gym" => "Converter interessados em matriculas e aulas experimentais.",
    "supermarket" => "Promover ofertas locais e fidelizacao recorrente.",
  ];

  return $map[$type] ??
    "Atrair mais clientes para " .
      mb_strtolower($categoryLabel) .
      " e melhorar a conversao digital.";
}

function inferSalesAngle(string $type, bool $hasWebsite): string
{
  $fallback = $hasWebsite
    ? "Modernizacao do site atual com foco em conversao e captacao."
    : "Criacao de landing page enxuta com foco em captacao imediata.";

  $map = [
    "restaurant" => "Landing page com cardapio, rota e botao de pedido.",
    "doctor" => "Landing page com especialidades, prova social e agendamento.",
    "dentist" => "Pagina com tratamentos, antes e depois e agendamento.",
    "lawyer" =>
      "Pagina institucional com areas de atuacao e captacao por WhatsApp.",
    "pharmacy" => "Pagina com catalogo basico e canal rapido de atendimento.",
    "car_repair" =>
      "Sistema simples para orcamento, checklist e retorno ao cliente.",
    "clothing_store" =>
      "Landing page para colecao, ofertas e catalogo por WhatsApp.",
    "lodging" => "Pagina com quartos, pacote e reserva direta.",
    "beauty_salon" => "Agenda online e promocao de servicos recorrentes.",
    "accounting" => "Landing page consultiva com formularios de qualificacao.",
    "pet_store" => "Pagina com servicos, banho/tosa e planos recorrentes.",
    "gym" => "Landing page com planos, aulas e teste gratis.",
    "supermarket" => "Pagina para encartes e campanhas geolocalizadas.",
  ];

  return $map[$type] ?? $fallback;
}

function buildProposal(array $lead, string $brand = "syncforge"): string
{
  $name = (string) ($lead["name"] ?? "sua empresa");
  $intent = mb_strtolower(
    (string) ($lead["intent"] ?? "aumentar a conversão digital"),
  );
  $categoryLabel = mb_strtolower(
    (string) ($lead["categoryLabel"] ?? "empresa"),
  );
  $salesAngle = mb_strtolower(
    (string) ($lead["salesAngle"] ?? "uma estrutura digital mais eficiente"),
  );
  $hasWebsite = !empty($lead["website"]);

  $brands = [
    "dg" => [
      "name" => "DG Computer",
      "tagline" =>
        "Tecnologia, presença digital e soluções para empresas locais.",
      "presentation" =>
        "trabalhamos com tecnologia, presença digital e soluções práticas para empresas que querem vender melhor e atender com mais agilidade.",
      "website" => "https://www.dgcomputer.com.br/",
    ],

    "syncforge" => [
      "name" => "Syncforge",
      "tagline" =>
        "Soluções digitais modernas focadas em conversão e crescimento.",
      "presentation" =>
        "criamos soluções digitais modernas com foco em conversão, atendimento e crescimento comercial.",
      "website" => "https://syncforge-business.vercel.app/",
    ],
  ];

  $selectedBrand = $brands[$brand] ?? $brands["syncforge"];

  $intro = "Oi, tudo bem? Aqui é da {$selectedBrand["name"]}. Nós {$selectedBrand["presentation"]}";

  $opening = "Dei uma olhada rápida na presença digital da {$name} e achei que valia te chamar porque existe uma boa oportunidade de {$intent}.";

  $problem = $hasWebsite
    ? "Vi que vocês já têm presença online, o que é ótimo. Mesmo assim, ainda dá para deixar essa estrutura mais alinhada para transformar visita em contato e contato em oportunidade real."
    : "Hoje muita empresa do segmento de {$categoryLabel} ainda depende só de indicação, Instagram ou busca local. Quando não existe uma estrutura própria bem organizada, muita oportunidade boa acaba se perdendo no caminho.";

  $market = "Principalmente nesse tipo de negócio, a pessoa normalmente decide rápido. Se encontra uma comunicação clara, confiança e um caminho simples para falar no WhatsApp, a chance de converter aumenta bastante.";

  $solution = "Pensando nisso, a ideia seria {$salesAngle}, de um jeito simples, direto e com foco comercial de verdade.";

  $details = "A proposta é organizar melhor a apresentação da empresa, destacar os diferenciais certos e facilitar o contato de quem já chega com interesse.";

  $cta = "Se fizer sentido, eu posso te mostrar uma ideia inicial pensada para {$name}, sem compromisso, para você visualizar como isso poderia funcionar na prática.";

  $signature = "{$selectedBrand["name"]}\n{$selectedBrand["website"]}";

  return fixMojibake(
    implode("\n\n", [
      $intro,
      $opening,
      $problem,
      $market,
      $solution,
      $details,
      $cta,
      $signature,
    ]),
  );
}

function buildSearchQuery(string $query, array $category): string
{
  if ($query !== "") {
    return $query . " em Manaus AM";
  }

  if (!empty($category["placeType"])) {
    return $category["label"] . " em Manaus AM";
  }

  return "empresas em Manaus AM";
}

function findCategory(array $categories, string $type): array
{
  foreach ($categories as $category) {
    if (($category["value"] ?? "") === $type) {
      return $category;
    }
  }

  return $categories[0];
}

function httpGetJson(string $url): array
{
  $context = stream_context_create([
    "http" => [
      "method" => "GET",
      "timeout" => 20,
      "ignore_errors" => true,
    ],
  ]);

  $raw = @file_get_contents($url, false, $context);
  if ($raw === false) {
    throw new RuntimeException("Falha ao conectar com a API da SerpAPI.");
  }

  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) {
    throw new RuntimeException("Resposta invalida da API da SerpAPI.");
  }

  return $decoded;
}
?>
<!doctype html>
<html lang="pt-BR">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>LeadMapper Manaus</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.34.0/dist/tabler-icons.min.css">
  <!-- CSS: tema visual, layout e responsividade -->
  <style>
    :root {
      --bg: #0f0f0f;
      --surface: #171717;
      --surface-soft: #202020;
      --surface-strong: #ffffff;
      --surface-muted: #f5f5f5;
      --text: #111111;
      --text-inverse: #ffffff;
      --muted: #8a8a8a;
      --muted-soft: #b8b8b8;
      --border: #2d2d2d;
      --primary: #ff7a00;
      --primary-strong: #cf6200;
      --danger: #ff5b3d;
      --radius-xl: 20px;
      --radius-lg: 16px;
      --radius-md: 12px;
      --shadow: 0 16px 36px rgba(0, 0, 0, 0.24);
      --font-sans: "Manrope", sans-serif;
    }

    * {
      box-sizing: border-box;
    }

    [hidden] {
      display: none !important;
    }

    html,
    body {
      margin: 0;
      min-height: 100%;
    }

    body {
      font-family: var(--font-sans);
      color: var(--text-inverse);
      background: var(--bg);
    }

    button,
    input,
    select {
      font: inherit;
    }

    .shell {
      max-width: 1440px;
      margin: 0 auto;
      padding: 16px;
    }

    .app {
      display: grid;
      grid-template-columns: 300px minmax(0, 1fr);
      gap: 14px;
      align-items: start;
    }

    .sidebar,
    .content {
      border-radius: var(--radius-xl);
      border: 1px solid var(--border);
      box-shadow: var(--shadow);
    }

    .sidebar {
      display: flex;
      flex-direction: column;
      min-height: calc(100vh - 32px);
      overflow: hidden;
      background: var(--surface);
    }

    .content {
      background: #101010;
      padding: 14px;
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 18px;
      border-bottom: 1px solid var(--border);
    }

    .brand-icon {
      width: 42px;
      height: 42px;
      display: grid;
      place-items: center;
      border-radius: 12px;
      background: var(--primary);
      color: #fff;
      font-size: 20px;
      flex-shrink: 0;
    }

    .brand-title {
      font-size: 16px;
      font-weight: 800;
      color: var(--text-inverse);
    }

    .brand-subtitle {
      color: var(--muted-soft);
      font-size: 12px;
    }

    .brand-status {
      display: flex;
      gap: 10px;
      align-items: flex-start;
      margin: 0 18px 18px;
      padding: 14px;
      border-radius: var(--radius-md);
      background: var(--surface-soft);
      border: 1px solid var(--border);
    }

    .status-dot {
      width: 12px;
      height: 12px;
      border-radius: 999px;
      margin-top: 4px;
      background: var(--primary);
      box-shadow: 0 0 0 6px rgba(255, 122, 0, 0.12);
    }

    .status-dot.live {
      background: var(--primary);
    }

    .status-dot.error {
      background: var(--danger);
      box-shadow: 0 0 0 6px rgba(255, 91, 61, 0.12);
    }

    .brand-status strong {
      display: block;
      margin-bottom: 4px;
      color: var(--text-inverse);
      font-size: 13px;
    }

    .brand-status p {
      margin: 0;
      color: var(--muted-soft);
      line-height: 1.5;
      font-size: 12px;
    }

    .search-panel {
      display: grid;
      gap: 14px;
      padding: 0 18px 18px;
    }

    .filter-divider {
      height: 1px;
      background: var(--border);
      margin: 4px 0;
    }

    .filter-hint {
      margin: -4px 0 0;
      color: var(--muted);
      font-size: 11px;
      line-height: 1.5;
    }

    .label {
      font-size: 11px;
      font-weight: 800;
      color: var(--muted-soft);
      text-transform: uppercase;
      letter-spacing: 0.08em;
    }

    .input-wrap {
      position: relative;
    }

    .input-wrap i {
      position: absolute;
      top: 50%;
      left: 14px;
      transform: translateY(-50%);
      color: var(--muted);
    }

    input,
    select,
    .primary-btn,
    .secondary-btn {
      width: 100%;
      min-height: 44px;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: #111111;
      color: var(--text-inverse);
    }

    input,
    select {
      padding: 0 14px;
    }

    .input-wrap input {
      padding-left: 42px;
    }

    .primary-btn,
    .secondary-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      cursor: pointer;
      font-weight: 700;
      transition: opacity .16s ease, transform .16s ease;
    }

    .primary-btn {
      background: var(--primary);
      border-color: var(--primary);
      color: #fff;
    }

    .secondary-btn {
      background: #111111;
      color: var(--text-inverse);
    }

    .primary-btn:hover,
    .secondary-btn:hover:not(:disabled) {
      transform: translateY(-1px);
      opacity: .96;
    }

    .secondary-btn:disabled {
      opacity: .45;
      cursor: not-allowed;
    }

    .sidebar-footer {
      margin-top: auto;
      padding: 18px;
      border-top: 1px solid var(--border);
      background: #121212;
    }

    .summary {
      margin-bottom: 14px;
      color: var(--muted-soft);
      font-size: 13px;
      line-height: 1.6;
    }

    .tabs {
      display: flex;
      gap: 8px;
      margin-bottom: 14px;
    }

    .tab {
      border: 0;
      background: #171717;
      border-radius: 999px;
      padding: 10px 16px;
      color: var(--muted-soft);
      cursor: pointer;
      font-weight: 700;
    }

    .tab.active {
      background: var(--primary);
      color: #fff;
    }

    .panel {
      display: none;
      min-height: calc(100vh - 88px);
      border-radius: var(--radius-lg);
      background: var(--surface-strong);
      border: 1px solid #2a2a2a;
      overflow: hidden;
    }

    .panel.active {
      display: block;
    }

    .panel-header {
      padding: 18px 18px 0;
      color: var(--text);
    }

    .panel-header h2,
    .proposal-head h2,
    .guide-card h2,
    .guide-card h3 {
      margin: 0;
      color: var(--text);
    }

    .panel-header p,
    .proposal-meta,
    .guide-card p,
    .guide-list {
      color: #5f5f5f;
    }

    .empty-state,
    .loading-state {
      min-height: 460px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 24px;
      color: var(--text);
    }

    .empty-state i {
      font-size: 46px;
      color: rgba(255, 122, 0, 0.7);
      margin-bottom: 12px;
    }

    .empty-state h3 {
      margin: 0 0 8px;
    }

    .empty-state p {
      max-width: 420px;
      margin: 0;
      color: #666666;
      line-height: 1.7;
    }

    .results-list {
      display: grid;
      gap: 14px;
      padding: 18px;
    }

    .result-card {
      padding: 18px;
      border-radius: 16px;
      background: #fff;
      border: 1px solid #ececec;
    }

    .result-main h3 {
      margin: 0 0 8px;
      font-size: 20px;
      color: var(--text);
    }

    .result-main p {
      color: #5f5f5f;
    }

    .result-tags {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-bottom: 12px;
    }

    .chip {
      display: inline-flex;
      align-items: center;
      min-height: 28px;
      padding: 0 12px;
      border-radius: 999px;
      background: #fff2e5;
      color: var(--primary-strong);
      font-size: 12px;
      font-weight: 700;
    }

    .chip.alert {
      background: #ffe7e2;
      color: var(--danger);
    }

    .chip.hot {
      background: #fff2e5;
      color: var(--primary-strong);
      border: 1px solid #ffd0a8;
    }

    .score-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 54px;
      padding: 0 12px;
      min-height: 32px;
      border-radius: 999px;
      background: #111111;
      color: #ffffff;
      font-size: 12px;
      font-weight: 800;
    }

    .result-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
      margin-top: 14px;
    }

    .data-box {
      padding: 12px 14px;
      border-radius: 12px;
      background: var(--surface-muted);
    }

    .data-box strong {
      display: block;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #6f6f6f;
      margin-bottom: 6px;
    }

    .data-box span,
    .data-box a {
      color: var(--text);
      line-height: 1.55;
      word-break: break-word;
    }

    .result-actions {
      display: flex;
      gap: 10px;
      margin-top: 16px;
    }

    .result-actions button {
      flex: 1;
    }

    .spinner {
      width: 42px;
      height: 42px;
      border-radius: 999px;
      border: 3px solid rgba(255, 122, 0, 0.18);
      border-top-color: var(--primary);
      animation: spin .8s linear infinite;
      margin-bottom: 14px;
    }

    .proposal-card {
      padding: 18px;
      color: var(--text);
    }

    .proposal-head {
      display: flex;
      justify-content: space-between;
      gap: 18px;
      align-items: flex-start;
      padding-bottom: 18px;
      border-bottom: 1px solid #ececec;
    }

    .proposal-actions {
      display: flex;
      gap: 10px;
    }

    .proposal-actions .secondary-btn {
      width: auto;
      padding: 0 16px;
    }

    .proposal-body {
      padding-top: 22px;
      font-size: 16px;
      line-height: 1.8;
      color: var(--text);
    }

    .proposal-body p {
      margin: 0 0 16px;
    }

    .guide-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 18px;
      padding: 18px;
    }

    .guide-card {
      padding: 18px;
      border-radius: 16px;
      background: #fff;
      border: 1px solid #ececec;
      color: var(--text);
    }

    .guide-card p,
    .guide-list {
      margin: 12px 0 0;
      line-height: 1.8;
    }

    .guide-list {
      padding-left: 18px;
    }

    .code-block {
      margin: 12px 0 0;
      padding: 16px;
      border-radius: 12px;
      overflow: auto;
      background: #111111;
      color: #ffffff;
      font-size: 13px;
      line-height: 1.7;
    }

    @keyframes spin {
      to {
        transform: rotate(360deg);
      }
    }

    @media (max-width: 1100px) {
      .app {
        grid-template-columns: 1fr;
      }

      .sidebar {
        min-height: auto;
      }
    }

    @media (max-width: 720px) {
      .shell {
        padding: 12px;
      }

      .content {
        padding: 12px;
      }

      .brand-status {
        margin: 0 16px 16px;
      }

      .proposal-head,
      .proposal-actions,
      .result-actions,
      .guide-grid {
        display: grid;
      }

      .result-grid,
      .guide-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>

<body>
  <!-- HTML: estrutura principal da interface -->
  <div class="shell">
    <main class="app">
      <aside class="sidebar">
        <div class="brand">
          <div class="brand-icon"><i class="ti ti-target-arrow" aria-hidden="true"></i></div>
          <div>
            <div class="brand-title">LeadMapper</div>
            <div class="brand-subtitle">Manaus - AM</div>
          </div>
        </div>

        <div class="brand-status">
          <span id="apiStatusDot" class="status-dot"></span>
          <div>
            <strong id="apiStatusLabel">Carregando ambiente</strong>
            <p id="apiStatusText">Verificando se a API da SerpAPI está configurada.</p>
          </div>
        </div>

        <form id="searchForm" class="search-panel">
          <label class="label" for="query">Busca</label>
          <div class="input-wrap">
            <i class="ti ti-search" aria-hidden="true"></i>
            <input id="query" name="query" type="text" placeholder="Ex.: clínica, restaurante, oficina, coroado">
          </div>

          <label class="label" for="type">Categoria</label>
          <select id="type" name="type"></select>

          <label class="label" for="radius">Raio de busca</label>
          <select id="radius" name="radius">
            <option value="3000">3 km</option>
            <option value="5000" selected>5 km</option>
            <option value="8000">8 km</option>
            <option value="12000">12 km</option>
          </select>

          <div class="filter-divider" aria-hidden="true"></div>

          <label class="label" for="districtFilter">Bairro</label>
          <input id="districtFilter" name="districtFilter" type="text" placeholder="Ex.: Centro, Adrianópolis">

          <label class="label" for="websiteFilter">Website</label>
          <select id="websiteFilter" name="websiteFilter">
            <option value="all">Todos</option>
            <option value="without">Sem site</option>
            <option value="with">Com site</option>
          </select>

          <label class="label" for="opportunityFilter">Oportunidade</label>
          <select id="opportunityFilter" name="opportunityFilter">
            <option value="all">Todos</option>
            <option value="hot">Lead quente</option>
            <option value="high_score">Score 70+</option>
            <option value="best_sale">Boa venda</option>
          </select>

          <label class="label" for="proposalBrand">Marca da proposta</label>
          <select id="proposalBrand" name="proposalBrand">
            <option value="syncforge" selected>SyncForge</option>
            <option value="dg">DG</option>
          </select>

          <p class="filter-hint">Esses filtros atuam localmente nos resultados carregados e não fazem nova consulta.</p>

          <button class="primary-btn" type="submit">
            <i class="ti ti-radar-2" aria-hidden="true"></i>
            Buscar leads
          </button>
        </form>

        <div class="sidebar-footer">
          <div id="summary" class="summary">Nenhuma busca executada.</div>
          <button id="exportBtn" class="secondary-btn" type="button" disabled>
            <i class="ti ti-file-download" aria-hidden="true"></i>
            Exportar CSV
          </button>
        </div>
      </aside>

      <section class="content">
        <div class="tabs">
          <button class="tab active" type="button" data-tab="results">Empresas</button>
          <button class="tab" type="button" data-tab="proposal">Proposta</button>
          <button class="tab" type="button" data-tab="guide">API Google</button>
        </div>

        <div class="panel active" id="panel-results">
          <div class="panel-header">
            <div>
              <h2>Resultados da busca</h2>
              <p>Empresas em Manaus com foco em prospecção de serviços digitais.</p>
            </div>
          </div>
          <div id="resultsState" class="empty-state">
            <i class="ti ti-map-search" aria-hidden="true"></i>
            <h3>Comece por uma busca</h3>
            <p>Use um termo como "clínica", "restaurante" ou "loja" e escolha o segmento.</p>
          </div>
          <div id="resultsList" class="results-list" hidden></div>
        </div>

        <div class="panel" id="panel-proposal">
          <div id="proposalEmpty" class="empty-state">
            <i class="ti ti-message-2-bolt" aria-hidden="true"></i>
            <h3>Selecione uma empresa</h3>
            <p>Depois clique em gerar proposta para montar uma abordagem comercial.</p>
          </div>
          <div id="proposalLoading" class="loading-state" hidden>
            <div class="spinner" aria-hidden="true"></div>
            <p>Montando proposta personalizada...</p>
          </div>
          <article id="proposalCard" class="proposal-card" hidden>
            <header class="proposal-head">
              <div>
                <h2 id="proposalCompany"></h2>
                <p id="proposalMeta" class="proposal-meta"></p>
              </div>
              <div class="proposal-actions">
                <button id="copyProposalBtn" class="secondary-btn" type="button"><i class="ti ti-copy"
                    aria-hidden="true"></i>Copiar</button>
                <button id="whatsAppBtn" class="secondary-btn" type="button"><i class="ti ti-brand-whatsapp"
                    aria-hidden="true"></i>WhatsApp</button>
              </div>
            </header>
            <div id="proposalBody" class="proposal-body"></div>
          </article>
        </div>

        <div class="panel" id="panel-guide">
          <div class="guide-grid">
            <section class="guide-card">
              <h2>SerpAPI em vez de Google Places direto</h2>
              <p>Para esse caso, a fonte principal agora é a SerpAPI com engine google_maps. Isso simplifica a coleta de
                nome, telefone, site, endereço, nota e reviews.</p>
            </section>
            <section class="guide-card">
              <h3>Como este arquivo funciona</h3>
              <ol class="guide-list">
                <li>O mesmo arquivo PHP entrega a interface e responde a API interna.</li>
                <li>As buscas usam SerpAPI com engine google_maps.</li>
                <li>Telefone, website, endereço, nota e reviews vêm do retorno da SerpAPI.</li>
                <li>Sem chave configurada, a busca não é executada.</li>
              </ol>
            </section>
            <section class="guide-card">
              <h3>Configurar</h3>
              <pre class="code-block">1. Configure SERP_API_KEY no ambiente do PHP
2. Rode um servidor PHP apontando para esta pasta
3. Abra este arquivo no navegador pelo servidor</pre>
            </section>
            <section class="guide-card">
              <h3>Dados retornados</h3>
              <ul class="guide-list">
                <li>Nome da empresa</li>
                <li>Telefone</li>
                <li>Endereço</li>
                <li>Website</li>
                <li>Avaliação</li>
                <li>Tipo de negócio</li>
                <li>Objetivo comercial inferido para a proposta</li>
              </ul>
            </section>
          </div>
        </div>
      </section>
    </main>
  </div>

  <!-- CONSUMI API JAVASCRIPT: interacoes da interface e chamadas para os endpoints PHP -->
  <script>
    const state = {
      allLeads: [],
      leads: [],
      selectedLead: null,
      proposalText: "",
      proposalBrand: "syncforge",
      lastSource: "serpapi"
    };

    const apiBase = "<?= htmlspecialchars(
      basename(__FILE__),
      ENT_QUOTES,
      "UTF-8",
    ) ?>";
    const elements = {
      type: document.getElementById("type"),
      query: document.getElementById("query"),
      radius: document.getElementById("radius"),
      districtFilter: document.getElementById("districtFilter"),
      websiteFilter: document.getElementById("websiteFilter"),
      opportunityFilter: document.getElementById("opportunityFilter"),
      proposalBrand: document.getElementById("proposalBrand"),
      searchForm: document.getElementById("searchForm"),
      resultsState: document.getElementById("resultsState"),
      resultsList: document.getElementById("resultsList"),
      summary: document.getElementById("summary"),
      exportBtn: document.getElementById("exportBtn"),
      tabs: Array.from(document.querySelectorAll(".tab")),
      panels: {
        results: document.getElementById("panel-results"),
        proposal: document.getElementById("panel-proposal"),
        guide: document.getElementById("panel-guide")
      },
      proposalEmpty: document.getElementById("proposalEmpty"),
      proposalLoading: document.getElementById("proposalLoading"),
      proposalCard: document.getElementById("proposalCard"),
      proposalCompany: document.getElementById("proposalCompany"),
      proposalMeta: document.getElementById("proposalMeta"),
      proposalBody: document.getElementById("proposalBody"),
      copyProposalBtn: document.getElementById("copyProposalBtn"),
      whatsAppBtn: document.getElementById("whatsAppBtn"),
      apiStatusDot: document.getElementById("apiStatusDot"),
      apiStatusLabel: document.getElementById("apiStatusLabel"),
      apiStatusText: document.getElementById("apiStatusText")
    };

    init();

    async function init() {
      state.proposalBrand = elements.proposalBrand.value;
      bindEvents();
      await loadConfig();
    }

    function bindEvents() {
      elements.searchForm.addEventListener("submit", onSearch);
      elements.exportBtn.addEventListener("click", exportCsv);
      elements.copyProposalBtn.addEventListener("click", copyProposal);
      elements.whatsAppBtn.addEventListener("click", openWhatsApp);
      elements.districtFilter.addEventListener("input", applyLocalFilters);
      elements.query.addEventListener("input", applyLocalFilters);
      elements.websiteFilter.addEventListener("change", applyLocalFilters);
      elements.opportunityFilter.addEventListener("change", applyLocalFilters);
      elements.proposalBrand.addEventListener("change", () => {
        state.proposalBrand = elements.proposalBrand.value;
      });

      for (const tab of elements.tabs) {
        tab.addEventListener("click", () => activateTab(tab.dataset.tab));
      }
    }

    async function loadConfig() {
      try {
        const response = await fetch(`${apiBase}?api=config`);
        const data = await response.json();
        renderCategoryOptions(data.categories || []);
        renderApiStatus(Boolean(data.googleMapsConfigured));
      } catch (error) {
        renderApiStatus(false, true);
      }
    }

    function renderCategoryOptions(categories) {
      elements.type.innerHTML = "";
      for (const item of categories) {
        const option = document.createElement("option");
        option.value = item.value;
        option.textContent = item.label;
        elements.type.appendChild(option);
      }
    }

    function renderApiStatus(isLive, isError = false) {
      elements.apiStatusDot.className = "status-dot";
      if (isError) {
        elements.apiStatusDot.classList.add("error");
        elements.apiStatusLabel.textContent = "Backend indisponivel";
        elements.apiStatusText.textContent = "Não foi possível ler a configuração do arquivo PHP.";
        return;
      }

      if (isLive) {
        elements.apiStatusDot.classList.add("live");
        elements.apiStatusLabel.textContent = "SerpAPI ativa";
        elements.apiStatusText.textContent = "As buscas usam dados reais da SerpAPI com engine google_maps.";
        return;
      }

      elements.apiStatusLabel.textContent = "Chave ausente";
      elements.apiStatusText.textContent = "Defina SERP_API_KEY no ambiente do PHP para habilitar as buscas.";
    }

    async function onSearch(event) {
      event.preventDefault();
      const params = new URLSearchParams({
        query: elements.query.value.trim(),
        type: elements.type.value,
        radius: elements.radius.value
      });

      console.log("[LeadMapper] Iniciando busca", {
        query: elements.query.value.trim(),
        type: elements.type.value,
        radius: elements.radius.value,
        districtFilter: elements.districtFilter.value.trim(),
        websiteFilter: elements.websiteFilter.value,
        opportunityFilter: elements.opportunityFilter.value
      });

      setResultsLoading();

      try {
        const response = await fetch(`${apiBase}?api=leads&${params.toString()}`);
        const data = await response.json();
        if (!response.ok) {
          throw new Error(data.error || "Erro ao consultar leads.");
        }

        state.allLeads = data.leads || [];
        state.lastSource = data.source || "serpapi";
        state.selectedLead = null;
        state.proposalText = "";
        console.log("[LeadMapper] Resultado bruto da busca", {
          total: state.allLeads.length,
          source: state.lastSource,
          leads: state.allLeads
        });
        applyLocalFilters();
        resetProposalView();
        activateTab("results");
      } catch (error) {
        console.error("[LeadMapper] Falha na busca", error);
        state.allLeads = [];
        state.leads = [];
        elements.resultsList.hidden = true;
        elements.resultsState.hidden = false;
        elements.resultsState.innerHTML = `
          <i class="ti ti-alert-triangle" aria-hidden="true"></i>
          <h3>Falha na busca</h3>
          <p>${escapeHtml(error.message)}</p>
        `;
        elements.summary.textContent = "A busca falhou.";
        elements.exportBtn.disabled = true;
      }
    }

    function setResultsLoading() {
      elements.resultsList.hidden = true;
      elements.resultsState.hidden = false;
      elements.resultsState.innerHTML = `
        <div class="spinner" aria-hidden="true"></div>
        <h3>Consultando empresas...</h3>
        <p>Buscando negócios da região e coletando telefone, site e endereço.</p>
      `;
    }

    function applyLocalFilters() {
      const query = elements.query.value.trim();
      const district = elements.districtFilter.value.trim();
      const websiteMode = elements.websiteFilter.value;
      const opportunityMode = elements.opportunityFilter.value;

      state.leads = state.allLeads.filter((lead) => {
        const searchableLead = [
          lead.name,
          lead.categoryLabel,
          lead.address,
          lead.phone,
          lead.website,
          lead.intent,
          lead.salesAngle
        ].filter(Boolean).join(" ");
        const hasWebsite = Boolean(lead.website);
        const score = Number(lead.score || 0);

        if (query && !matchesSearch(String(searchableLead || ""), query)) {
          return false;
        }

        if (district && !matchesSearch(String(lead.address || ""), district)) {
          return false;
        }

        if (websiteMode === "with" && !hasWebsite) {
          return false;
        }

        if (websiteMode === "without" && hasWebsite) {
          return false;
        }

        if (opportunityMode === "hot" && score < 70) {
          return false;
        }

        if (opportunityMode === "high_score" && score < 70) {
          return false;
        }

        if (opportunityMode === "best_sale" && !(score >= 70 || !hasWebsite)) {
          return false;
        }

        return true;
      });

      console.log("[LeadMapper] Resultado apos filtros locais", {
        query,
        district,
        websiteMode,
        opportunityMode,
        totalLoaded: state.allLeads.length,
        totalFiltered: state.leads.length,
        leads: state.leads
      });

      renderSummary();
      renderResults();
    }

    function matchesSearch(text, search) {
      const normalizedText = normalizeSearchText(text);
      const normalizedSearch = normalizeSearchText(search);

      if (!normalizedSearch) {
        return true;
      }

      if (normalizedText.includes(normalizedSearch)) {
        return true;
      }

      const textTokens = normalizedText.split(/\s+/).filter(Boolean);
      const searchTokens = normalizedSearch.split(/\s+/).filter(Boolean);

      return searchTokens.every((searchToken) =>
        textTokens.some((textToken) =>
          textToken.includes(searchToken) ||
          searchToken.includes(textToken) ||
          isApproximateMatch(textToken, searchToken)
        )
      );
    }

    function normalizeSearchText(value) {
      return String(value || "")
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .replace(/\bii\b/g, "2")
        .replace(/\biii\b/g, "3")
        .replace(/\biv\b/g, "4")
        .replace(/\bvi\b/g, "6")
        .replace(/\bvii\b/g, "7")
        .replace(/\bviii\b/g, "8")
        .replace(/\bix\b/g, "9")
        .replace(/\bv\b/g, "5")
        .replace(/\bi\b/g, "1")
        .replace(/[^a-z0-9\s]/gi, " ")
        .replace(/\s+/g, " ")
        .trim()
        .toLowerCase();
    }

    function isApproximateMatch(textToken, searchToken) {
      if (!textToken || !searchToken) {
        return false;
      }

      if (searchToken.length <= 2) {
        return textToken.startsWith(searchToken);
      }

      if (Math.abs(textToken.length - searchToken.length) > 2) {
        return false;
      }

      return levenshteinDistance(textToken, searchToken) <= 1;
    }

    function levenshteinDistance(a, b) {
      const rows = a.length + 1;
      const cols = b.length + 1;
      const matrix = Array.from({ length: rows }, () => Array(cols).fill(0));

      for (let i = 0; i < rows; i += 1) {
        matrix[i][0] = i;
      }

      for (let j = 0; j < cols; j += 1) {
        matrix[0][j] = j;
      }

      for (let i = 1; i < rows; i += 1) {
        for (let j = 1; j < cols; j += 1) {
          const cost = a[i - 1] === b[j - 1] ? 0 : 1;
          matrix[i][j] = Math.min(
            matrix[i - 1][j] + 1,
            matrix[i][j - 1] + 1,
            matrix[i - 1][j - 1] + cost
          );
        }
      }

      return matrix[a.length][b.length];
    }

    function renderSummary() {
      const totalLoaded = state.allLeads.length;
      const filteredCount = state.leads.length;
      const withoutWebsite = state.leads.filter((lead) => !lead.website).length;
      const hotLeads = state.leads.filter((lead) => Number(lead.score || 0) >= 70).length;
      const filterSuffix = totalLoaded && filteredCount !== totalLoaded ? `<br>${filteredCount} apos filtros` : "";
      elements.summary.innerHTML = `${totalLoaded} empresas carregadas${filterSuffix}<br>${withoutWebsite} sem website<br>${hotLeads} leads quentes<br>Fonte: ${escapeHtml(state.lastSource || "serpapi")}`;
      elements.exportBtn.disabled = state.leads.length === 0;
    }

    function renderResults() {
      if (!state.leads.length) {
        elements.resultsList.hidden = true;
        elements.resultsState.hidden = false;
        elements.resultsState.innerHTML = `
          <i class="ti ti-building-store" aria-hidden="true"></i>
          <h3>${state.allLeads.length ? "Nenhum lead corresponde aos filtros" : "Nenhum lead encontrado"}</h3>
          <p>${state.allLeads.length ? "Ajuste bairro, site ou oportunidade para ampliar os resultados." : "Tente mudar o termo ou aumentar o raio de busca."}</p>
        `;
        return;
      }

      elements.resultsState.hidden = true;
      elements.resultsList.hidden = false;
      elements.resultsList.innerHTML = state.leads.map(renderLeadCard).join("");

      for (const button of elements.resultsList.querySelectorAll("[data-action]")) {
        button.addEventListener("click", handleLeadAction);
      }
    }

    function renderLeadCard(lead) {
      const websiteContent = lead.website
        ? `<a href="${escapeAttribute(lead.website)}" target="_blank" rel="noreferrer">${escapeHtml(lead.website)}</a>`
        : "Sem website identificado";
      const score = Number(lead.score || 0);
      const hotBadge = score >= 70 ? '<span class="chip hot">Lead quente</span>' : "";
      const noWebsiteBadge = !lead.website ? '<span class="chip alert">Sem site</span>' : "";
      const reviewCount = Number(lead.reviews || 0);
      const ratingValue = Number(lead.rating || 0);
      const ratingChipClass = ratingValue >= 4.3 ? "chip hot" : "chip";

      return `
        <article class="result-card">
          <div class="result-main">
            <h3>${escapeHtml(lead.name)}</h3>
            <div class="result-tags">
              <span class="chip">${escapeHtml(lead.categoryLabel)}</span>
              ${noWebsiteBadge}
              ${hotBadge}
              <span class="${ratingChipClass}">Nota ${escapeHtml(String(lead.rating || "n/d"))}</span>
              <span class="score-badge">Score ${escapeHtml(String(score))}</span>
            </div>
            <p>${escapeHtml(lead.intent)}</p>
          </div>
          <div class="result-grid">
            <div class="data-box">
              <strong>Telefone</strong>
              <span>${escapeHtml(lead.phone || "Não encontrado")}</span>
            </div>
            <div class="data-box">
              <strong>Website</strong>
              <span>${websiteContent}</span>
            </div>
            <div class="data-box">
              <strong>Endereço</strong>
              <span>${escapeHtml(lead.address || "Não informado")}</span>
            </div>
            <div class="data-box">
              <strong>Reviews</strong>
              <span>${escapeHtml(String(reviewCount))}</span>
            </div>
            <div class="data-box">
              <strong>Proxima abordagem</strong>
              <span>${escapeHtml(lead.salesAngle)}</span>
            </div>
          </div>
          <div class="result-actions">
            <button class="primary-btn" type="button" data-action="proposal" data-id="${escapeAttribute(lead.id)}">
              <i class="ti ti-sparkles" aria-hidden="true"></i>Gerar proposta
            </button>
            <button class="secondary-btn" type="button" data-action="select" data-id="${escapeAttribute(lead.id)}">
              <i class="ti ti-eye" aria-hidden="true"></i>Ver detalhes
            </button>
          </div>
        </article>
      `;
    }

    function handleLeadAction(event) {
      const action = event.currentTarget.dataset.action;
      const lead = state.leads.find((item) => item.id === event.currentTarget.dataset.id);
      if (!lead) {
        return;
      }

      state.selectedLead = lead;
      if (action === "select") {
        activateTab("proposal");
        renderSelectedLeadPlaceholder();
        return;
      }

      if (action === "proposal") {
        generateProposal(lead);
      }
    }

    function renderSelectedLeadPlaceholder() {
      elements.proposalEmpty.hidden = false;
      elements.proposalLoading.hidden = true;
      elements.proposalCard.hidden = true;
      elements.proposalEmpty.innerHTML = `
        <i class="ti ti-building" aria-hidden="true"></i>
        <h3>${escapeHtml(state.selectedLead.name)}</h3>
        <p>Clique em "Gerar proposta" no card do lead para montar um texto de abordagem.</p>
      `;
    }

    async function generateProposal(lead) {
      activateTab("proposal");
      elements.proposalEmpty.hidden = true;
      elements.proposalCard.hidden = true;
      elements.proposalLoading.hidden = false;

      try {
        const response = await fetch(`${apiBase}?api=proposal`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ lead, brand: state.proposalBrand })
        });
        const data = await response.json();
        if (!response.ok) {
          throw new Error(data.error || "Não foi possível gerar a proposta.");
        }

        state.selectedLead = lead;
        state.proposalText = data.proposal || "";
        renderProposal(state.proposalText);
      } catch (error) {
        elements.proposalLoading.hidden = true;
        elements.proposalEmpty.hidden = false;
        elements.proposalEmpty.innerHTML = `
          <i class="ti ti-alert-triangle" aria-hidden="true"></i>
          <h3>Falha ao gerar proposta</h3>
          <p>${escapeHtml(error.message)}</p>
        `;
      }
    }

    function renderProposal(text) {
      elements.proposalLoading.hidden = true;
      elements.proposalCard.hidden = false;
      elements.proposalCompany.textContent = state.selectedLead.name;
      const brandLabel = state.proposalBrand === "dg" ? "DG" : "SyncForge";
      elements.proposalMeta.textContent = `${brandLabel} - ${state.selectedLead.categoryLabel} - ${state.selectedLead.phone || "sem telefone"} - ${state.selectedLead.address || "Manaus"}`;
      elements.proposalBody.innerHTML = text
        .split("\n\n")
        .map((paragraph) => `<p>${escapeHtml(paragraph)}</p>`)
        .join("");
    }

    function resetProposalView() {
      state.proposalText = "";
      elements.proposalLoading.hidden = true;
      elements.proposalCard.hidden = true;
      elements.proposalEmpty.hidden = false;
      elements.proposalEmpty.innerHTML = `
        <i class="ti ti-message-2-bolt" aria-hidden="true"></i>
        <h3>Selecione uma empresa</h3>
        <p>Depois clique em gerar proposta para montar uma abordagem comercial.</p>
      `;
    }

    function activateTab(tabName) {
      for (const tab of elements.tabs) {
        tab.classList.toggle("active", tab.dataset.tab === tabName);
      }
      for (const [name, panel] of Object.entries(elements.panels)) {
        panel.classList.toggle("active", name === tabName);
      }
    }

    async function copyProposal() {
      if (!state.proposalText) {
        return;
      }

      await navigator.clipboard.writeText(state.proposalText);
      const original = elements.copyProposalBtn.innerHTML;
      elements.copyProposalBtn.innerHTML = '<i class="ti ti-check" aria-hidden="true"></i>Copiado';
      setTimeout(() => {
        elements.copyProposalBtn.innerHTML = original;
      }, 1600);
    }

    function openWhatsApp() {
      if (!state.selectedLead || !state.proposalText) {
        return;
      }

      const digits = (state.selectedLead.phone || "").replace(/\D/g, "");
      const message = encodeURIComponent(`Olá, ${state.selectedLead.name}.\n\nPreparei uma ideia inicial de proposta para o seu negócio:\n\n${state.proposalText}`);
      const url = digits ? `https://wa.me/55${digits}?text=${message}` : `https://wa.me/?text=${message}`;
      window.open(url, "_blank", "noopener,noreferrer");
    }

    function exportCsv() {
      if (!state.leads.length) {
        return;
      }

      const rows = [
        ["Nome", "Categoria", "Telefone", "Endereço", "Website", "Avaliação", "Reviews", "Score", "Objetivo", "Abordagem"],
        ...state.leads.map((lead) => [
          lead.name,
          lead.categoryLabel,
          lead.phone || "",
          lead.address || "",
          lead.website || "",
          lead.rating || "",
          lead.reviews || 0,
          lead.score || 0,
          lead.intent,
          lead.salesAngle
        ])
      ];

      const csv = "\uFEFF" + rows.map((row) => row.map(escapeCsv).join(";")).join("\n");
      const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement("a");
      anchor.href = url;
      anchor.download = "leads_manaus.csv";
      anchor.click();
      URL.revokeObjectURL(url);
    }

    function escapeCsv(value) {
      return `"${String(value).replace(/"/g, '""')}"`;
    }

    function escapeHtml(value) {
      return String(value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
    }

    function escapeAttribute(value) {
      return escapeHtml(value);
    }
  </script>
</body>

</html>
