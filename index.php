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

    if ($_GET["api"] === "study") {
      $payload = json_decode(
        file_get_contents("php://input") ?: "{}",
        true,
        512,
        JSON_THROW_ON_ERROR,
      );
      if (!is_array($payload) || empty($payload["lead"]["name"])) {
        http_response_code(400);
        sendJson(["error" => "Lead invalido para gerar estudo."]);
      }

      sendJson([
        "study" => buildCompanyStudy($payload["lead"]),
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
  echo json_encode(
    $payload,
    JSON_UNESCAPED_UNICODE |
      JSON_UNESCAPED_SLASHES |
      JSON_INVALID_UTF8_SUBSTITUTE,
  );
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
  $phone = normalizeLeadPhone((string) ($place["phone"] ?? ""));
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

function normalizeLeadPhone(string $value): string
{
  $digits = preg_replace('/\D+/', '', trim($value)) ?? '';
  if ($digits === '') {
    return '';
  }

  if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
    $digits = substr($digits, 2);
  }

  return formatBrazilPhone($digits);
}

function formatBrazilPhone(string $digits): string
{
  if (strlen($digits) === 11) {
    return sprintf(
      '(%s) %s-%s',
      substr($digits, 0, 2),
      substr($digits, 2, 5),
      substr($digits, 7, 4),
    );
  }

  if (strlen($digits) === 10) {
    return sprintf(
      '(%s) %s-%s',
      substr($digits, 0, 2),
      substr($digits, 2, 4),
      substr($digits, 6, 4),
    );
  }

  return $digits;
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
    "restaurant" => "atrair mais reservas, pedidos e movimento nos horários de pico",
    "doctor" =>
      "transformar pesquisas locais em agendamentos e transmitir mais confiança",
    "dentist" => "gerar mais consultas particulares e valorizar os principais tratamentos",
    "lawyer" => "captar contatos mais qualificados e reforçar autoridade",
    "pharmacy" => "estimular pedidos rápidos e facilitar o contato com a loja",
    "car_repair" => "receber mais pedidos de orçamento e organizar melhor o atendimento",
    "clothing_store" => "aumentar visitas e dar mais força para campanhas e coleções",
    "lodging" => "receber mais reservas diretas sem depender só de plataformas externas",
    "beauty_salon" => "facilitar agendamentos e aumentar a recorrência dos clientes",
    "accounting" =>
      "atrair empresas locais interessadas em suporte recorrente",
    "pet_store" => "gerar mais pedidos e fortalecer a procura por serviços recorrentes",
    "gym" => "transformar interesse em matrículas e aulas experimentais",
    "supermarket" => "divulgar melhor ofertas locais e incentivar a fidelização",
  ];

  return $map[$type] ??
    "atrair mais clientes e melhorar a conversão digital";
}

function inferSalesAngle(string $type, bool $hasWebsite): string
{
  $fallback = $hasWebsite
    ? "modernizar o site atual com foco em conversão e captação"
    : "criar uma landing page enxuta, clara e focada em captação";

  $map = [
    "restaurant" => "criar uma landing page com cardápio, rota e botão de pedido",
    "doctor" => "criar uma landing page com especialidades, prova social e agendamento",
    "dentist" => "criar uma página com tratamentos, autoridade e agendamento facilitado",
    "lawyer" =>
      "criar uma página institucional com áreas de atuação e captação por WhatsApp",
    "pharmacy" => "criar uma página com catálogo básico e canal rápido de atendimento",
    "car_repair" =>
      "montar uma estrutura simples para orçamento, checklist e retorno ao cliente",
    "clothing_store" =>
      "criar uma landing page para coleções, ofertas e catálogo por WhatsApp",
    "lodging" => "criar uma página com quartos, pacotes e reserva direta",
    "beauty_salon" => "organizar uma agenda online e divulgar melhor os serviços recorrentes",
    "accounting" => "criar uma Landing Page consultiva com formulário de qualificação",
    "pet_store" => "criar uma página com serviços, banho e tosa e planos recorrentes",
    "gym" => "criar uma landing page com planos, aulas e teste experimental",
    "supermarket" => "criar uma página para encartes e campanhas geolocalizadas",
  ];

  return $map[$type] ?? $fallback;
}

function cleanProposalFragment(string $value): string
{
  return trim((string) preg_replace('/[\s\.,;:!?]+$/u', '', trim($value)));
}

function inferSalesExplanation(string $type, bool $hasWebsite): string
{
  $fallback = $hasWebsite
    ? "Na prática, isso significa reorganizar a estrutura atual para deixar mais claro quem a empresa é, o que oferece e qual o melhor caminho para o cliente entrar em contato."
    : "Na prática, isso significa criar uma página objetiva, leve e direta, apresentando a empresa, os serviços, os diferenciais e um caminho simples para o cliente falar com vocês pelo WhatsApp.";

  $map = [
    "restaurant" => "Na prática, seria uma página enxuta mostrando o cardápio, a localização, os principais diferenciais e um botão direto para pedido ou atendimento no WhatsApp.",
    "doctor" => "Na prática, seria uma página clara e profissional mostrando especialidades, credibilidade, formas de atendimento e um caminho simples para agendamento.",
    "dentist" => "Na prática, seria uma página apresentando os tratamentos, os diferenciais da clínica e um caminho direto para avaliação ou agendamento.",
    "lawyer" => "Na prática, seria uma página institucional mais clara, mostrando áreas de atuação, autoridade e um canal direto para contato.",
    "pharmacy" => "Na prática, seria uma página simples para apresentar a loja, facilitar o atendimento e orientar rapidamente quem procura produtos ou suporte.",
    "car_repair" => "Na prática, seria uma estrutura simples para apresentar os serviços, passar confiança e facilitar pedidos de orçamento pelo WhatsApp.",
    "clothing_store" => "Na prática, seria uma página para apresentar coleções, ofertas e facilitar o contato de quem quer comprar ou tirar dúvidas rapidamente.",
    "lodging" => "Na prática, seria uma página para mostrar quartos, diferenciais, localização e facilitar reservas diretas com menos atrito.",
    "beauty_salon" => "Na prática, seria uma página para apresentar serviços, reforçar os diferenciais do atendimento e facilitar agendamentos.",
    "accounting" => "Na prática, seria uma página mais consultiva, explicando os serviços, transmitindo confiança e facilitando o contato de empresas interessadas.",
    "pet_store" => "Na prática, seria uma página para mostrar serviços, produtos e facilitar o contato de clientes que querem comprar ou agendar atendimento.",
    "gym" => "Na prática, seria uma página para apresentar planos, aulas, estrutura e facilitar o contato de quem quer fazer uma aula experimental ou matrícula.",
    "supermarket" => "Na prática, seria uma página para divulgar ofertas, encartes e campanhas de forma simples e fácil de acessar.",
  ];

  return $map[$type] ?? $fallback;
}

function buildCompanyStudy(array $lead): array
{
  $name = (string) ($lead["name"] ?? "Empresa");
  $type = normalizePlaceType((string) ($lead["category"] ?? "all"));
  $website = (string) ($lead["website"] ?? "");
  $intent = cleanProposalFragment(
    mb_strtolower((string) ($lead["intent"] ?? "atrair mais clientes")),
  );
  $salesAngle = cleanProposalFragment(
    mb_strtolower(
      (string) ($lead["salesAngle"] ?? "criar uma estrutura digital mais eficiente"),
    ),
  );
  $profile = resolveStudyProfile($type);
  $websiteStudy = analyzeLeadWebsite($website);
  $palette = buildStudyPalette($websiteStudy["colors"], $profile["palette"]);
  $siteSummary = buildWebsiteStudySummary($websiteStudy, $website !== "");
  $visualDirection = buildVisualDirectionSummary($websiteStudy, $palette, $profile);
  $prompt = buildStudyPrompt(
    $name,
    $lead,
    $profile,
    $palette,
    $websiteStudy,
    $intent,
    $salesAngle,
  );

  return [
    "company" => $name,
    "area" => $profile["label"],
    "summary" => "A oportunidade principal aqui é {$intent}. Para este caso, o MVP precisa comunicar valor rápido, explicar o serviço com clareza e levar o visitante para um contato direto sem fricção.",
    "siteSummary" => $siteSummary,
    "visualDirection" => $visualDirection,
    "deliveryView" => inferSalesExplanation($type, $website !== ""),
    "references" => $profile["references"],
    "palette" => $palette,
    "prompt" => $prompt,
  ];
}

function resolveStudyProfile(string $type): array
{
  $profiles = [
    "restaurant" => [
      "label" => "restaurante e alimentação",
      "audience" => "pessoas que querem entender rapidamente o cardápio, a proposta da casa e como pedir ou reservar",
      "goal" => "gerar pedidos, reservas ou conversas no WhatsApp",
      "palette" => [
        ["label" => "Primária", "hex" => "#B45309", "role" => "CTA e destaques"],
        ["label" => "Apoio", "hex" => "#7C2D12", "role" => "blocos e ícones"],
        ["label" => "Superfície", "hex" => "#FFF7ED", "role" => "fundos suaves"],
        ["label" => "Texto", "hex" => "#1C1917", "role" => "legibilidade"],
      ],
      "references" => [
        ["name" => "Sweetgreen", "url" => "https://www.sweetgreen.com/", "reason" => "boa hierarquia entre produto, marca e CTA"],
        ["name" => "Shake Shack", "url" => "https://shakeshack.com/", "reason" => "apresentação simples e conversão rápida"],
        ["name" => "Nando's", "url" => "https://www.nandos.co.uk/", "reason" => "uso controlado de cor e navegação direta"],
      ],
    ],
    "health" => [
      "label" => "saúde e atendimento clínico",
      "audience" => "pessoas buscando confiança, clareza sobre serviços e uma forma simples de agendar",
      "goal" => "gerar agendamentos e contatos qualificados",
      "palette" => [
        ["label" => "Primária", "hex" => "#0F766E", "role" => "CTA e confiança"],
        ["label" => "Apoio", "hex" => "#155E75", "role" => "ícones e seções"],
        ["label" => "Superfície", "hex" => "#F0FDFA", "role" => "fundos suaves"],
        ["label" => "Texto", "hex" => "#0F172A", "role" => "legibilidade"],
      ],
      "references" => [
        ["name" => "One Medical", "url" => "https://www.onemedical.com/", "reason" => "tom confiável e comunicação simples"],
        ["name" => "Zocdoc", "url" => "https://www.zocdoc.com/", "reason" => "clareza na jornada de agendamento"],
        ["name" => "Cleveland Clinic", "url" => "https://my.clevelandclinic.org/", "reason" => "boa organização de informação sensível"],
      ],
    ],
    "lawyer" => [
      "label" => "serviços jurídicos",
      "audience" => "pessoas ou empresas que precisam entender especialidades, confiança e caminho de contato",
      "goal" => "captar contatos qualificados e transmitir autoridade",
      "palette" => [
        ["label" => "Primária", "hex" => "#1D4ED8", "role" => "CTA e destaques"],
        ["label" => "Apoio", "hex" => "#1E293B", "role" => "áreas institucionais"],
        ["label" => "Superfície", "hex" => "#F8FAFC", "role" => "fundos limpos"],
        ["label" => "Texto", "hex" => "#0F172A", "role" => "legibilidade"],
      ],
      "references" => [
        ["name" => "Cooley", "url" => "https://www.cooley.com/", "reason" => "institucional moderno sem excesso"],
        ["name" => "WilmerHale", "url" => "https://www.wilmerhale.com/", "reason" => "boa hierarquia e tom sério"],
        ["name" => "Latham", "url" => "https://www.lw.com/", "reason" => "estrutura confiável e objetiva"],
      ],
    ],
    "pharmacy" => [
      "label" => "farmácia e conveniência em saúde",
      "audience" => "clientes que querem agilidade, localização clara e uma forma simples de atendimento",
      "goal" => "facilitar pedidos e atendimento rápido",
      "palette" => [
        ["label" => "Primária", "hex" => "#059669", "role" => "CTA e destaques"],
        ["label" => "Apoio", "hex" => "#0F766E", "role" => "seções de apoio"],
        ["label" => "Superfície", "hex" => "#ECFDF5", "role" => "fundos suaves"],
        ["label" => "Texto", "hex" => "#111827", "role" => "legibilidade"],
      ],
      "references" => [
        ["name" => "CVS", "url" => "https://www.cvs.com/", "reason" => "clareza na organização e utilidade"],
        ["name" => "Walgreens", "url" => "https://www.walgreens.com/", "reason" => "estrutura prática para necessidade imediata"],
        ["name" => "Boots", "url" => "https://www.boots.com/", "reason" => "boa mistura entre serviço e produto"],
      ],
    ],
    "car_repair" => [
      "label" => "oficina e serviços automotivos",
      "audience" => "motoristas que precisam confiar rápido, entender serviços e pedir orçamento",
      "goal" => "gerar pedidos de orçamento e contatos diretos",
      "palette" => [
        ["label" => "Primária", "hex" => "#DC2626", "role" => "CTA e alertas leves"],
        ["label" => "Apoio", "hex" => "#1F2937", "role" => "bases visuais"],
        ["label" => "Superfície", "hex" => "#F9FAFB", "role" => "fundos limpos"],
        ["label" => "Texto", "hex" => "#111827", "role" => "legibilidade"],
      ],
      "references" => [
        ["name" => "Midas", "url" => "https://www.midas.com/", "reason" => "serviços claros e CTA rápido"],
        ["name" => "Meineke", "url" => "https://www.meineke.com/", "reason" => "estrutura direta para orçamento"],
        ["name" => "Jiffy Lube", "url" => "https://www.jiffylube.com/", "reason" => "boa comunicação de serviço local"],
      ],
    ],
    "clothing_store" => [
      "label" => "varejo de moda",
      "audience" => "clientes que querem entender a coleção, ver diferenciais e pedir atendimento rápido",
      "goal" => "apresentar produtos e gerar contato comercial",
      "palette" => [
        ["label" => "Primária", "hex" => "#7C3AED", "role" => "destaques moderados"],
        ["label" => "Apoio", "hex" => "#374151", "role" => "estrutura"],
        ["label" => "Superfície", "hex" => "#FAFAFA", "role" => "fundos limpos"],
        ["label" => "Texto", "hex" => "#111827", "role" => "legibilidade"],
      ],
      "references" => [
        ["name" => "Everlane", "url" => "https://www.everlane.com/", "reason" => "produto e marca bem equilibrados"],
        ["name" => "Aritzia", "url" => "https://www.aritzia.com/", "reason" => "boa apresentação de coleção"],
        ["name" => "COS", "url" => "https://www.cos.com/", "reason" => "visual contido e sofisticado"],
      ],
    ],
    "lodging" => [
      "label" => "hotelaria e hospedagem",
      "audience" => "pessoas que querem visualizar quartos, localização e reservar com facilidade",
      "goal" => "gerar reservas diretas e contatos rápidos",
      "palette" => [
        ["label" => "Primária", "hex" => "#0F766E", "role" => "CTA e identidade"],
        ["label" => "Apoio", "hex" => "#1E293B", "role" => "blocos informativos"],
        ["label" => "Superfície", "hex" => "#F8FAFC", "role" => "fundos claros"],
        ["label" => "Texto", "hex" => "#0F172A", "role" => "legibilidade"],
      ],
      "references" => [
        ["name" => "Ace Hotel", "url" => "https://acehotel.com/", "reason" => "boa atmosfera sem perder clareza"],
        ["name" => "Nobis Hotel", "url" => "https://www.nobishotel.com/", "reason" => "luxo contido e navegação simples"],
        ["name" => "The Hoxton", "url" => "https://thehoxton.com/", "reason" => "conteúdo forte com boa conversão"],
      ],
    ],
    "beauty_salon" => [
      "label" => "beleza e estética",
      "audience" => "clientes que procuram confiança visual, serviços claros e agendamento fácil",
      "goal" => "gerar agendamentos e recorrência",
      "palette" => [
        ["label" => "Primária", "hex" => "#DB2777", "role" => "CTA e destaques"],
        ["label" => "Apoio", "hex" => "#7C2D12", "role" => "blocos e contraste"],
        ["label" => "Superfície", "hex" => "#FFF1F2", "role" => "fundos suaves"],
        ["label" => "Texto", "hex" => "#1F2937", "role" => "legibilidade"],
      ],
      "references" => [
        ["name" => "Drybar", "url" => "https://www.drybar.com/", "reason" => "apresentação de serviço direta"],
        ["name" => "Glossier", "url" => "https://www.glossier.com/", "reason" => "uso equilibrado de visual e marca"],
        ["name" => "Treatwell", "url" => "https://www.treatwell.com/", "reason" => "foco em serviço e agendamento"],
      ],
    ],
    "accounting" => [
      "label" => "contabilidade e consultoria",
      "audience" => "empresas que precisam entender o serviço, confiar no time e pedir contato",
      "goal" => "gerar leads mais qualificados",
      "palette" => [
        ["label" => "Primária", "hex" => "#2563EB", "role" => "CTA e confiança"],
        ["label" => "Apoio", "hex" => "#0F172A", "role" => "bases institucionais"],
        ["label" => "Superfície", "hex" => "#F8FAFC", "role" => "fundos limpos"],
        ["label" => "Texto", "hex" => "#111827", "role" => "legibilidade"],
      ],
      "references" => [
        ["name" => "Pilot", "url" => "https://pilot.com/", "reason" => "explica serviço complexo com clareza"],
        ["name" => "Bench", "url" => "https://www.bench.co/", "reason" => "boa proposta de valor e CTA"],
        ["name" => "Xero", "url" => "https://www.xero.com/", "reason" => "equilíbrio entre confiança e simplicidade"],
      ],
    ],
    "pet_store" => [
      "label" => "pet shop e cuidados pet",
      "audience" => "tutores que querem entender serviços, produtos e contato rápido",
      "goal" => "gerar pedidos e agendamentos",
      "palette" => [
        ["label" => "Primária", "hex" => "#EA580C", "role" => "CTA e destaques"],
        ["label" => "Apoio", "hex" => "#0F766E", "role" => "apoio visual"],
        ["label" => "Superfície", "hex" => "#FFF7ED", "role" => "fundos suaves"],
        ["label" => "Texto", "hex" => "#1F2937", "role" => "legibilidade"],
      ],
      "references" => [
        ["name" => "BarkBox", "url" => "https://barkbox.com/", "reason" => "tom acessível e visual simpático"],
        ["name" => "Chewy", "url" => "https://www.chewy.com/", "reason" => "boa organização de oferta"],
        ["name" => "Petco", "url" => "https://www.petco.com/", "reason" => "estrutura de serviço e produto"],
      ],
    ],
    "gym" => [
      "label" => "academia e fitness",
      "audience" => "pessoas interessadas em planos, estrutura e uma primeira conversa rápida",
      "goal" => "gerar matrículas e aulas experimentais",
      "palette" => [
        ["label" => "Primária", "hex" => "#2563EB", "role" => "CTA e energia"],
        ["label" => "Apoio", "hex" => "#111827", "role" => "estrutura"],
        ["label" => "Superfície", "hex" => "#F8FAFC", "role" => "fundos limpos"],
        ["label" => "Texto", "hex" => "#0F172A", "role" => "legibilidade"],
      ],
      "references" => [
        ["name" => "Equinox", "url" => "https://www.equinox.com/", "reason" => "boa apresentação aspiracional com clareza"],
        ["name" => "Barry's", "url" => "https://www.barrys.com/", "reason" => "forte conversão com identidade visual controlada"],
        ["name" => "Peloton", "url" => "https://www.onepeloton.com/", "reason" => "boa composição entre conteúdo e CTA"],
      ],
    ],
    "supermarket" => [
      "label" => "supermercado e varejo alimentar",
      "audience" => "clientes que querem ver ofertas, localização e formas rápidas de atendimento",
      "goal" => "gerar visitas, pedidos e recorrência",
      "palette" => [
        ["label" => "Primária", "hex" => "#16A34A", "role" => "CTA e promoções"],
        ["label" => "Apoio", "hex" => "#166534", "role" => "apoio visual"],
        ["label" => "Superfície", "hex" => "#F0FDF4", "role" => "fundos suaves"],
        ["label" => "Texto", "hex" => "#111827", "role" => "legibilidade"],
      ],
      "references" => [
        ["name" => "Whole Foods", "url" => "https://www.wholefoodsmarket.com/", "reason" => "boa organização de informação comercial"],
        ["name" => "Publix", "url" => "https://www.publix.com/", "reason" => "ofertas e estrutura simples"],
        ["name" => "Mercadona", "url" => "https://www.mercadona.es/", "reason" => "comunicação objetiva e direta"],
      ],
    ],
    "design" => [
      "label" => "design, comunicação visual e impressão",
      "audience" => "clientes que precisam entender rápido os serviços, ver credibilidade e pedir orçamento",
      "goal" => "gerar pedidos de orçamento e conversas qualificadas",
      "palette" => [
        ["label" => "Primária", "hex" => "#F97316", "role" => "CTA e destaques"],
        ["label" => "Apoio", "hex" => "#111827", "role" => "bases e contraste"],
        ["label" => "Superfície", "hex" => "#FFF7ED", "role" => "fundos suaves"],
        ["label" => "Texto", "hex" => "#0F172A", "role" => "legibilidade"],
      ],
      "references" => [
        ["name" => "Pentagram", "url" => "https://www.pentagram.com/", "reason" => "apresentação clara de portfólio e marca"],
        ["name" => "Instrument", "url" => "https://www.instrument.com/", "reason" => "visual forte sem exagero gratuito"],
        ["name" => "COLLINS", "url" => "https://www.wearecollins.com/", "reason" => "narrativa visual bem resolvida"],
      ],
    ],
    "technology" => [
      "label" => "tecnologia e software",
      "audience" => "empresas ou usuários que precisam entender rápido o produto e a proposta de valor",
      "goal" => "gerar demonstrações, testes ou contatos comerciais",
      "palette" => [
        ["label" => "Primária", "hex" => "#2563EB", "role" => "CTA e destaques"],
        ["label" => "Apoio", "hex" => "#0F172A", "role" => "base institucional"],
        ["label" => "Superfície", "hex" => "#F8FAFC", "role" => "fundos limpos"],
        ["label" => "Texto", "hex" => "#111827", "role" => "legibilidade"],
      ],
      "references" => [
        ["name" => "Vercel", "url" => "https://vercel.com/", "reason" => "clareza de produto e estrutura moderna"],
        ["name" => "Linear", "url" => "https://linear.app/", "reason" => "MVP visualmente forte e contido"],
        ["name" => "Framer", "url" => "https://www.framer.com/", "reason" => "boa hierarquia e CTA"],
      ],
    ],
    "generic" => [
      "label" => "serviços locais e negócios de atendimento",
      "audience" => "clientes que precisam entender a empresa rápido e encontrar um canal claro de contato",
      "goal" => "gerar conversas comerciais e pedidos de orçamento",
      "palette" => [
        ["label" => "Primária", "hex" => "#F97316", "role" => "CTA e destaques"],
        ["label" => "Apoio", "hex" => "#111827", "role" => "estrutura"],
        ["label" => "Superfície", "hex" => "#F8FAFC", "role" => "fundos limpos"],
        ["label" => "Texto", "hex" => "#0F172A", "role" => "legibilidade"],
      ],
      "references" => [
        ["name" => "Stripe", "url" => "https://stripe.com/", "reason" => "clareza na proposta de valor"],
        ["name" => "Square", "url" => "https://squareup.com/", "reason" => "boa estrutura comercial"],
        ["name" => "Mailchimp", "url" => "https://mailchimp.com/", "reason" => "marketing visual sem exagero"],
      ],
    ],
  ];

  $aliases = [
    "doctor" => "health",
    "dentist" => "health",
    "drafting_service" => "design",
    "advertising_agency" => "design",
    "banner_store" => "design",
    "graphic_designer" => "design",
    "commercial_printer" => "design",
    "digital_printer" => "design",
    "print_shop" => "design",
    "digital_printing_service" => "design",
    "vinyl_sign_shop" => "design",
    "software_company" => "technology",
    "computer_store" => "technology",
    "electronics_store" => "technology",
  ];

  $profileKey = $aliases[$type] ?? $type;

  return $profiles[$profileKey] ?? $profiles["generic"];
}

function analyzeLeadWebsite(string $website): array
{
  $normalized = normalizeWebsiteUrl($website);
  if ($normalized === "") {
    return [
      "available" => false,
      "source" => "",
      "title" => "",
      "description" => "",
      "colors" => [],
    ];
  }

  try {
    $html = httpGetText($normalized);
  } catch (Throwable $e) {
    error_log(
      sprintf(
        '[LeadMapper] Nao foi possivel analisar website "%s": %s',
        $normalized,
        $e->getMessage(),
      ),
    );

    return [
      "available" => false,
      "source" => $normalized,
      "title" => "",
      "description" => "",
      "colors" => [],
    ];
  }

  return [
    "available" => true,
    "source" => $normalized,
    "title" => fixMojibake(extractHtmlTitle($html)),
    "description" => fixMojibake(extractMetaDescription($html)),
    "colors" => extractDominantHexColors($html),
  ];
}

function normalizeWebsiteUrl(string $website): string
{
  $value = trim($website);
  if ($value === "") {
    return "";
  }

  if (!preg_match('~^https?://~i', $value)) {
    $value = "https://" . $value;
  }

  return $value;
}

function buildStudyPalette(array $observedColors, array $fallbackPalette): array
{
  $palette = $fallbackPalette;

  if (!empty($observedColors[0])) {
    $palette[0]["hex"] = $observedColors[0];
  }

  if (!empty($observedColors[1])) {
    $palette[1]["hex"] = $observedColors[1];
  }

  return $palette;
}

function buildWebsiteStudySummary(array $websiteStudy, bool $hasWebsite): string
{
  if (!$hasWebsite) {
    return "Não foi identificado um website ativo no lead. Para este estudo, o MVP deve partir do zero com foco em explicar a empresa, mostrar serviços e abrir um caminho claro para contato.";
  }

  if (!$websiteStudy["available"]) {
    return "O lead possui website informado, mas não foi possível analisar a página automaticamente agora. Ainda assim, faz sentido preparar um MVP com estrutura enxuta, mais clara e orientada à conversão.";
  }

  $parts = ["Foi possível observar um website ativo como ponto de referência."];

  if ($websiteStudy["title"] !== "") {
    $parts[] = "Título observado: {$websiteStudy["title"]}.";
  }

  if ($websiteStudy["description"] !== "") {
    $parts[] = "Resumo aparente da página: {$websiteStudy["description"]}.";
  }

  if ($websiteStudy["colors"] !== []) {
    $parts[] = "Também foi possível identificar algumas cores presentes no site atual, o que ajuda a manter alguma familiaridade visual sem exagerar na paleta.";
  }

  return implode(" ", $parts);
}

function buildVisualDirectionSummary(array $websiteStudy, array $palette, array $profile): string
{
  $primary = $palette[0]["hex"] ?? "#F97316";
  $support = $palette[1]["hex"] ?? "#111827";

  if ($websiteStudy["colors"] !== []) {
    return "A direção visual pode aproveitar a leitura do site atual usando {$primary} como cor principal e {$support} como apoio, sempre com bastante respiro, fundo claro e contraste forte para não exagerar nem parecer poluído.";
  }

  return "Como não há leitura visual forte do site atual, a recomendação é seguir uma paleta contida e funcional, com {$primary} para CTA e {$support} para estrutura, mantendo aparência profissional, moderna e fácil de vender como MVP.";
}

function buildStudyPrompt(
  string $companyName,
  array $lead,
  array $profile,
  array $palette,
  array $websiteStudy,
  string $intent,
  string $salesAngle,
): string {
  $referenceLines = array_map(
    static fn(array $item): string =>
      "- {$item["name"]}: {$item["url"]} ({$item["reason"]})",
    $profile["references"],
  );
  $paletteLines = array_map(
    static fn(array $item): string =>
      "- {$item["label"]}: {$item["hex"]} para {$item["role"]}",
    $palette,
  );
  $siteNotes = [];
  if (!empty($websiteStudy["title"])) {
    $siteNotes[] = "Título observado no site atual: {$websiteStudy["title"]}";
  }
  if (!empty($websiteStudy["description"])) {
    $siteNotes[] = "Descrição observada no site atual: {$websiteStudy["description"]}";
  }
  if ($siteNotes === []) {
    $siteNotes[] = "Não dependa de um site atual forte; assuma que o MVP precisa organizar melhor a apresentação da empresa desde o começo.";
  }

  return fixMojibake(implode("\n", [
    "Você é um desenvolvedor frontend sênior preparando um MVP de landing page demonstrativa com mentalidade de produto e conversão.",
    "",
    "Contexto do projeto:",
    "- Empresa: {$companyName}",
    "- Área de atuação: {$profile["label"]}",
    "- Público principal: {$profile["audience"]}",
    "- Objetivo de negócio: {$intent}",
    "- Entrega sugerida: {$salesAngle}",
    "- Meta do MVP: {$profile["goal"]}",
    "",
    "Leitura de negócio:",
    ...array_map(static fn(string $item): string => "- {$item}", $siteNotes),
    "- A landing page deve explicar de forma clara quem é a empresa, o que ela oferece, por que ela é confiável e como o cliente entra em contato.",
    "- Faça sentido comercial antes de tentar parecer sofisticado demais.",
    "",
    "Direção visual:",
    "- Use uma estética moderna, sóbria e vendável.",
    "- Evite cores exageradas, gradientes pesados, efeitos chamativos ou UI com cara de template genérico.",
    "- Trabalhe com hierarquia visual forte, bastante respiro e contraste limpo.",
    ...$paletteLines,
    "",
    "Estrutura esperada do MVP:",
    "- Hero com proposta de valor clara, subtítulo humano e CTA principal para WhatsApp/orçamento.",
    "- Seção sobre a empresa explicando rapidamente credibilidade, posicionamento e diferenciais.",
    "- Seção de serviços principais com cards objetivos.",
    "- Seção de prova social com placeholders de depoimentos, logos ou cases.",
    "- Seção visual mostrando como o serviço se apresenta na prática.",
    "- FAQ curto com objeções comuns.",
    "- CTA final forte e rodapé simples.",
    "",
    "Regras importantes do MVP:",
    "- Trate isso explicitamente como MVP: nada de área logada, dashboard, fluxo complexo ou funcionalidades irreais.",
    "- Onde faltarem ativos reais, use placeholders explícitos como [Placeholder: foto da equipe], [Placeholder: imagem do serviço], [Placeholder: mockup da entrega], [Placeholder: fachada ou ambiente].",
    "- A copy precisa soar humana, comercial e específica para a área, sem parecer texto de IA genérico.",
    "- Priorize responsividade desktop/mobile.",
    "- O resultado deve ser uma landing page demonstrativa que sirva bem para apresentar a ideia ao cliente.",
    "",
    "Sites de referência para direção estrutural:",
    ...$referenceLines,
    "",
    "Entrega esperada:",
    "- Gere a landing page completa do MVP com foco em frontend.",
    "- Se precisar inventar conteúdo visual, mantenha placeholders claros em vez de fingir imagens finais.",
    "- Pense e execute como um dev frontend sênior montando algo enxuto, convincente e pronto para demonstração.",
  ]));
}

function httpGetText(string $url): string
{
  $context = stream_context_create([
    "http" => [
      "method" => "GET",
      "timeout" => 12,
      "ignore_errors" => true,
      "header" => "User-Agent: LeadMapper/1.0\r\nAccept: text/html,*/*;q=0.8\r\n",
    ],
    "ssl" => [
      "verify_peer" => false,
      "verify_peer_name" => false,
    ],
  ]);

  $raw = @file_get_contents($url, false, $context);
  if ($raw === false || trim($raw) === "") {
    throw new RuntimeException("Falha ao carregar o website informado.");
  }

  $converted = @mb_convert_encoding($raw, "UTF-8", "UTF-8, ISO-8859-1, Windows-1252");

  return is_string($converted) && $converted !== "" ? $converted : $raw;
}

function extractHtmlTitle(string $html): string
{
  if (!preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
    return "";
  }

  return html_entity_decode(trim(strip_tags($matches[1])), ENT_QUOTES | ENT_HTML5, "UTF-8");
}

function extractMetaDescription(string $html): string
{
  if (
    !preg_match(
      '/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/is',
      $html,
      $matches,
    ) &&
    !preg_match(
      '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']description["\']/is',
      $html,
      $matches,
    )
  ) {
    return "";
  }

  return html_entity_decode(trim($matches[1]), ENT_QUOTES | ENT_HTML5, "UTF-8");
}

function extractDominantHexColors(string $html): array
{
  preg_match_all('/#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})\b/', $html, $matches);
  if (empty($matches[0])) {
    return [];
  }

  $ignored = ["#FFFFFF", "#FFF", "#000000", "#000", "#111111", "#F8F8F8", "#F5F5F5"];
  $counts = [];
  foreach ($matches[0] as $color) {
    $normalized = normalizeHexColor($color);
    if ($normalized === "" || in_array($normalized, $ignored, true)) {
      continue;
    }

    $counts[$normalized] = ($counts[$normalized] ?? 0) + 1;
  }

  arsort($counts);

  return array_slice(array_keys($counts), 0, 4);
}

function normalizeHexColor(string $value): string
{
  $color = strtoupper(trim($value));
  if (!preg_match('/^#([0-9A-F]{3}|[0-9A-F]{6})$/', $color)) {
    return "";
  }

  if (strlen($color) === 4) {
    return sprintf(
      "#%s%s%s%s%s%s",
      $color[1],
      $color[1],
      $color[2],
      $color[2],
      $color[3],
      $color[3],
    );
  }

  return $color;
}

function buildProposal(array $lead, string $brand = "syncforge"): string
{
  $name = (string) ($lead["name"] ?? "sua empresa");
  $intent = cleanProposalFragment(
    mb_strtolower((string) ($lead["intent"] ?? "aumentar a conversão digital")),
  );
  $salesAngle = cleanProposalFragment(
    mb_strtolower(
      (string) ($lead["salesAngle"] ?? "criar uma estrutura digital mais eficiente"),
    ),
  );
  $hasWebsite = !empty($lead["website"]);
  $type = (string) ($lead["category"] ?? "all");
  $salesExplanation = inferSalesExplanation($type, $hasWebsite);

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

  $intro = "Oi, tudo bem? Me chamo Davi Peterson e faço parte da equipe da {$selectedBrand["name"]}.";

  $opening = "Nós {$selectedBrand["presentation"]}";

  $context = "Dei uma olhada rápida na presença digital da {$name} e achei que valia te chamar porque existe uma boa oportunidade de {$intent}.";

  $problem = $hasWebsite
    ? "Vi que vocês já têm presença online, o que é ótimo. Mesmo assim, ainda dá para deixar essa estrutura mais alinhada para transformar visita em contato e contato em oportunidade real."
    : "Hoje muitas empresas ainda dependem só de indicação, Instagram ou busca local. Quando não existe uma estrutura própria bem organizada, muita oportunidade boa acaba se perdendo no caminho.";

  $market = "Principalmente nesse tipo de negócio, a pessoa normalmente decide rápido. Se encontra uma comunicação clara, confiança e um caminho simples para falar no WhatsApp, a chance de converter aumenta bastante.";

  $solution = "Pensando nisso, a ideia seria {$salesAngle}, de um jeito simples, direto e com foco comercial de verdade.";

  $details = $salesExplanation;

  $cta = "Se fizer sentido, eu posso te mostrar uma ideia inicial pensada para {$name}, sem compromisso, para você visualizar como isso poderia funcionar na prática.";

  $signature = "{$selectedBrand["name"]}\n{$selectedBrand["website"]}";

  return fixMojibake(
    implode("\n\n", [
      $intro,
      $opening,
      $context,
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
    .study-head h2,
    .guide-card h2,
    .guide-card h3,
    .study-card h3 {
      margin: 0;
      color: var(--text);
    }

    .panel-header p,
    .proposal-meta,
    .study-meta,
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

    .study-card {
      padding: 18px;
      color: var(--text);
    }

    .study-head {
      display: flex;
      justify-content: space-between;
      gap: 18px;
      align-items: flex-start;
      padding-bottom: 18px;
      border-bottom: 1px solid #ececec;
    }

    .study-actions {
      display: flex;
      gap: 10px;
    }

    .study-actions .secondary-btn {
      width: auto;
      padding: 0 16px;
    }

    .study-body {
      padding-top: 22px;
      display: grid;
      gap: 18px;
    }

    .study-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 18px;
    }

    .study-block {
      padding: 18px;
      border-radius: 16px;
      background: var(--surface-muted);
      border: 1px solid #ececec;
    }

    .study-block.full {
      grid-column: 1 / -1;
    }

    .study-block p,
    .study-block li {
      color: #444444;
      line-height: 1.7;
    }

    .study-block p {
      margin: 12px 0 0;
    }

    .study-block ul {
      margin: 12px 0 0;
      padding-left: 18px;
    }

    .palette-list {
      display: grid;
      gap: 12px;
      margin-top: 12px;
    }

    .palette-item {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .palette-swatch {
      width: 44px;
      height: 44px;
      border-radius: 12px;
      border: 1px solid rgba(0, 0, 0, 0.08);
      flex-shrink: 0;
    }

    .palette-copy {
      font-size: 12px;
      color: #6b7280;
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
      .study-head,
      .study-actions,
      .proposal-actions,
      .result-actions,
      .guide-grid {
        display: grid;
      }

      .result-grid,
      .guide-grid,
      .study-grid {
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
          <button class="tab" type="button" data-tab="study">Estudo</button>
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

        <div class="panel" id="panel-study">
          <div id="studyEmpty" class="empty-state">
            <i class="ti ti-bulb" aria-hidden="true"></i>
            <h3>Selecione uma empresa</h3>
            <p>Depois clique em gerar estudo para montar um briefing com análise, referências e prompt de MVP.</p>
          </div>
          <div id="studyLoading" class="loading-state" hidden>
            <div class="spinner" aria-hidden="true"></div>
            <p>Estudando a empresa e preparando o prompt do MVP...</p>
          </div>
          <article id="studyCard" class="study-card" hidden>
            <header class="study-head">
              <div>
                <h2 id="studyCompany"></h2>
                <p id="studyMeta" class="study-meta"></p>
              </div>
              <div class="study-actions">
                <button id="copyStudyPromptBtn" class="secondary-btn" type="button"><i class="ti ti-copy"
                    aria-hidden="true"></i>Copiar prompt</button>
              </div>
            </header>
            <div id="studyBody" class="study-body"></div>
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
      studyData: null,
      studyPrompt: "",
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
        study: document.getElementById("panel-study"),
        guide: document.getElementById("panel-guide")
      },
      proposalEmpty: document.getElementById("proposalEmpty"),
      proposalLoading: document.getElementById("proposalLoading"),
      proposalCard: document.getElementById("proposalCard"),
      proposalCompany: document.getElementById("proposalCompany"),
      proposalMeta: document.getElementById("proposalMeta"),
      proposalBody: document.getElementById("proposalBody"),
      copyProposalBtn: document.getElementById("copyProposalBtn"),
      studyEmpty: document.getElementById("studyEmpty"),
      studyLoading: document.getElementById("studyLoading"),
      studyCard: document.getElementById("studyCard"),
      studyCompany: document.getElementById("studyCompany"),
      studyMeta: document.getElementById("studyMeta"),
      studyBody: document.getElementById("studyBody"),
      copyStudyPromptBtn: document.getElementById("copyStudyPromptBtn"),
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
      elements.copyStudyPromptBtn.addEventListener("click", copyStudyPrompt);
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
        state.studyData = null;
        state.studyPrompt = "";
        console.log("[LeadMapper] Resultado bruto da busca", {
          total: state.allLeads.length,
          source: state.lastSource,
          leads: state.allLeads
        });
        applyLocalFilters();
        resetProposalView();
        resetStudyView();
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
            <button class="secondary-btn" type="button" data-action="study" data-id="${escapeAttribute(lead.id)}">
              <i class="ti ti-bulb" aria-hidden="true"></i>Gerar estudo
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
      if (action === "study") {
        generateStudy(lead);
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

    async function generateStudy(lead) {
      activateTab("study");
      elements.studyEmpty.hidden = true;
      elements.studyCard.hidden = true;
      elements.studyLoading.hidden = false;

      console.log("[LeadMapper] Iniciando estudo da empresa", lead);

      try {
        const response = await fetch(`${apiBase}?api=study`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ lead })
        });
        const data = await response.json();
        if (!response.ok) {
          throw new Error(data.error || "Não foi possível gerar o estudo.");
        }

        state.selectedLead = lead;
        state.studyData = data.study || null;
        state.studyPrompt = (data.study && data.study.prompt) || "";
        console.log("[LeadMapper] Estudo gerado", state.studyData);
        renderStudy(state.studyData);
      } catch (error) {
        console.error("[LeadMapper] Falha ao gerar estudo", error);
        elements.studyLoading.hidden = true;
        elements.studyEmpty.hidden = false;
        elements.studyEmpty.innerHTML = `
          <i class="ti ti-alert-triangle" aria-hidden="true"></i>
          <h3>Falha ao gerar estudo</h3>
          <p>${escapeHtml(error.message)}</p>
        `;
      }
    }

    function renderStudy(study) {
      if (!study) {
        resetStudyView();
        return;
      }

      elements.studyLoading.hidden = true;
      elements.studyCard.hidden = false;
      elements.studyCompany.textContent = (state.selectedLead && state.selectedLead.name) || study.company || "Empresa";
      elements.studyMeta.textContent = `${study.area || "Estudo"} - ${(state.selectedLead && state.selectedLead.phone) || "sem telefone"} - ${(state.selectedLead && state.selectedLead.address) || "Manaus"}`;
      elements.studyBody.innerHTML = `
        <div class="study-grid">
          <section class="study-block">
            <h3>Leitura do negocio</h3>
            <p>${escapeHtml(study.summary || "")}</p>
          </section>
          <section class="study-block">
            <h3>Estrutura atual</h3>
            <p>${escapeHtml(study.siteSummary || "")}</p>
          </section>
          <section class="study-block">
            <h3>Direcao visual</h3>
            <p>${escapeHtml(study.visualDirection || "")}</p>
          </section>
          <section class="study-block">
            <h3>Entrega sugerida</h3>
            <p>${escapeHtml(study.deliveryView || "")}</p>
          </section>
          <section class="study-block">
            <h3>Paleta sugerida</h3>
            <div class="palette-list">${renderStudyPalette(study.palette || [])}</div>
          </section>
          <section class="study-block">
            <h3>Referencias de estrutura</h3>
            <ul>${renderStudyReferences(study.references || [])}</ul>
          </section>
          <section class="study-block full">
            <h3>Prompt de MVP</h3>
            <pre class="code-block">${escapeHtml(study.prompt || "")}</pre>
          </section>
        </div>
      `;
    }

    function renderStudyPalette(palette) {
      return palette.map((item) => `
        <div class="palette-item">
          <span class="palette-swatch" style="background:${escapeAttribute(item.hex || "#FFFFFF")}"></span>
          <div>
            <strong>${escapeHtml(item.label || "Cor")}</strong><br>
            <span>${escapeHtml(item.hex || "")}</span><br>
            <span class="palette-copy">${escapeHtml(item.role || "")}</span>
          </div>
        </div>
      `).join("");
    }

    function renderStudyReferences(references) {
      return references.map((item) => `
        <li><a href="${escapeAttribute(item.url || "#")}" target="_blank" rel="noreferrer">${escapeHtml(item.name || "Referencia")}</a> - ${escapeHtml(item.reason || "")}</li>
      `).join("");
    }

    function resetStudyView() {
      state.studyData = null;
      state.studyPrompt = "";
      elements.studyLoading.hidden = true;
      elements.studyCard.hidden = true;
      elements.studyEmpty.hidden = false;
      elements.studyEmpty.innerHTML = `
        <i class="ti ti-bulb" aria-hidden="true"></i>
        <h3>Selecione uma empresa</h3>
        <p>Depois clique em gerar estudo para montar um briefing com análise, referências e prompt de MVP.</p>
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

    async function copyStudyPrompt() {
      if (!state.studyPrompt) {
        return;
      }

      await navigator.clipboard.writeText(state.studyPrompt);
      const original = elements.copyStudyPromptBtn.innerHTML;
      elements.copyStudyPromptBtn.innerHTML = '<i class="ti ti-check" aria-hidden="true"></i>Copiado';
      setTimeout(() => {
        elements.copyStudyPromptBtn.innerHTML = original;
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
