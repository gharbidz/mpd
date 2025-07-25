<?php
// السماح بالوصول من أي نطاق (ضروري لـ CORS)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, User-Agent, Referer");
header("Access-Control-Expose-Headers: Content-Length, Content-Type, ETag, Cache-Control, Content-Encoding");

// التعامل مع طلبات OPTIONS المسبقة (Preflight requests for CORS)
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// التأكد من وجود بارامتر 'src' في الرابط
if (!isset($_GET['src'])) {
    http_response_code(400);
    die("Error: 'src' parameter is missing.");
}

$targetUrl = urldecode($_GET['src']);

// التحقق من صحة URL
if (!filter_var($targetUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    die("Error: Invalid URL provided.");
}

// تهيئة cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $targetUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // لإرجاع المحتوى كـ string
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // لاتباع عمليات إعادة التوجيه
curl_setopt($ch, CURLOPT_HEADER, false); // عدم تضمين هيدرات الاستجابة في الإخراج المباشر
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // لتجاهل التحقق من شهادة SSL (ليس موصى به للإنتاج ولكن قد يحل مشاكل)
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // لتجاهل التحقق من اسم المضيف لشهادة SSL

// =======================================================
//  الجديد: فرض هيدرات User-Agent و Origin و Referer المحددة
// =======================================================
$requestHeaders = [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36',
    'Origin: https://fnjplay.xyz',
    'Referer: https://fnjplay.xyz/', // غالباً ما يتم إضافة شرطة مائلة في النهاية للـ Referer
];

// يمكنك إضافة أي هيدرات أخرى ضرورية هنا إذا لزم الأمر، أو إزالة أي هيدرات لا ترغب في إرسالها
// مثال: 'X-Requested-With: XMLHttpRequest',
// 'Accept: */*',
// 'Accept-Language: en-US,en;q=0.9',
// 'Connection: keep-alive',

curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);

// =======================================================
//  الجديد: تمرير هيدرات الاستجابة من الخادم المستهدف إلى العميل
// =======================================================
// لجمع هيدرات الاستجابة
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) {
    $len = strlen($header);
    $header = explode(':', $header, 2);
    if (count($header) < 2) // ignore invalid headers
        return $len;

    $name = strtolower(trim($header[0]));
    $value = trim($header[1]);

    // هيدرات معينة قد تسبب مشاكل أو لا نحتاج لتمريرها
    // Content-Encoding و Content-Length سيتم التعامل معهما لاحقاً أو بواسطة cURL
    $blockedHeaders = ['content-encoding', 'content-length', 'transfer-encoding', 'strict-transport-security', 'alt-svc', 'access-control-allow-origin'];

    if (!in_array($name, $blockedHeaders)) {
        header("{$name}: {$value}");
    }
    return $len;
});

// تنفيذ طلب cURL
$responseContent = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

// ضبط رمز حالة HTTP ونوع المحتوى
http_response_code($httpCode);
header("Content-Type: " . ($contentType ?: "application/octet-stream")); // تعيين نوع المحتوى الافتراضي

// معالجة الأخطاء
if (curl_errno($ch)) {
    // إذا كان هناك خطأ في cURL، قم بإرجاع رمز خطأ وسبب الخطأ
    http_response_code(500);
    die("cURL Error: " . curl_error($ch));
}

curl_close($ch);

// تعديل محتوى M3U8 إذا كان الملف من نوع M3U8
if (strpos($contentType, 'application/vnd.apple.mpegurl') !== false || strpos($contentType, 'audio/mpegurl') !== false || strpos($contentType, 'application/x-mpegURL') !== false) {
    $lines = explode("\n", $responseContent);
    $modifiedContent = [];
    $baseUrl = ''; // لتخزين العنوان الأساسي إذا كان موجودًا في M3U8

    foreach ($lines as $line) {
        // إذا كان السطر يبدأ بـ #EXT-X-STREAM-INF أو #EXT-X-MEDIA، قد يحتوي على روابط نسبية
        if (strpos($line, '#EXT-X-STREAM-INF') !== false || strpos($line, '#EXT-X-MEDIA') !== false) {
            $modifiedContent[] = $line; // أضف السطر كما هو
        }
        // إذا كان السطر يمثل رابطًا لمقطع فيديو أو M3U8 فرعي
        else if (preg_match('/^(https?:\/\/[^\s]+)$/i', $line, $matches) || (strpos($line, '.m3u8') !== false && !strpos($line, '#EXT')) || (strpos($line, '.ts') !== false && !strpos($line, '#EXT')) || (strpos($line, '.aac') !== false && !strpos($line, '#EXT'))) {
            // حل الروابط النسبية
            $fullUrl = $line;
            if (strpos($line, '://') === false) { // إذا كان رابطًا نسبيًا
                // تحتاج إلى معرفة الرابط الأساسي للملف الأصلي لجعله مطلقًا
                // يمكن أن يكون هذا معقدًا، ولكن في معظم الحالات، إذا كانت الروابط نسبية
                // فإنها تكون بالنسبة لعنوان الـ M3U8 نفسه
                // هنا نفترض أن الروابط النسبية هي بالنسبة للـ targetUrl
                $fullUrl = rtrim(dirname($targetUrl), '/') . '/' . ltrim($line, '/');
            }
            // تشفير الرابط الأصلي وتمريره عبر البروكسي الخاص بنا
            $modifiedContent[] = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . '?src=' . urlencode($fullUrl);
        } else {
            $modifiedContent[] = $line;
        }
    }
    $responseContent = implode("\n", $modifiedContent);
}

echo $responseContent;
?>
