<?php
header('Content-Type: text/html; charset=utf-8');
echo "<html dir='rtl'><head><meta charset='utf-8'><title>تحديث يدوي</title>";
echo "<style>body{font-family:Arial;padding:20px;background:#f5f5f5}";
echo ".box{background:white;padding:20px;margin:10px 0;border-radius:8px}";
echo "input,select{padding:10px;margin:5px;border:1px solid #ddd;border-radius:4px;font-size:16px;width:300px}";
echo "button{background:#007bff;color:white;padding:12px 24px;border:none;border-radius:4px;cursor:pointer;font-size:16px}";
echo ".success{background:#d4edda;padding:20px;border-radius:8px;margin:10px 0;font-size:20px}";
echo ".error{background:#f8d7da;padding:20px;border-radius:8px;margin:10px 0;font-size:20px}";
echo "</style></head><body>";
echo "<h1>🔄 تحديث يدوي من Digylog</h1>";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
  $name = isset($_POST['name']) ? trim($_POST['name']) : '';
  $city = isset($_POST['city']) ? trim($_POST['city']) : '';
  $status = isset($_POST['status']) ? trim($_POST['status']) : '';
  
  $payload = array('status' => $status);
  if ($phone) $payload['phone'] = $phone;
  if ($name) $payload['name'] = $name;
  if ($city) $payload['city'] = $city;
  
  $ch = curl_init('https://dkhol.xyz/api.php?action=digylog_webhook');
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
  curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $response = curl_exec($ch);
  curl_close($ch);
  
  echo "<div class='box'><h2>النتيجة:</h2>";
  echo "<pre>" . htmlspecialchars($response) . "</pre>";
  
  $result = json_decode($response, true);
  if (isset($result['updated']) && $result['updated']) {
    echo "<div class='success'>✅ نجح! تم تحديث الطلبية</div>";
  } else {
    echo "<div class='error'>❌ ما تبدلاتش الطلبية</div>";
  }
  echo "</div>";
}

echo "<div class='box'><h2>📝 أدخل معلومات الزبون:</h2>";
echo "<form method='POST'>";
echo "<p>الهاتف:<br><input type='text' name='phone' placeholder='0640811937'></p>";
echo "<p>الاسم:<br><input type='text' name='name' placeholder='Fatima'></p>";
echo "<p>المدينة:<br><input type='text' name='city' placeholder='Agadir'></p>";
echo "<p>الحالة:<br><select name='status'>";
echo "<option value='delivered'>✅ Livrée</option>";
echo "<option value='returned'>🔄 Retour</option>";
echo "<option value='cancelled'>❌ Annulé</option>";
echo "<option value='shipped'>📦 Expédié</option>";
echo "</select></p>";
echo "<p><button type='submit'>🔄 تحديث</button></p>";
echo "</form></div></body></html>";