<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>استيراد البيانات - SAS CRM</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            max-width: 600px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        h1 {
            color: #667eea;
            text-align: center;
            margin-bottom: 10px;
            font-size: 28px;
        }
        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .status {
            padding: 15px;
            border-radius: 10px;
            margin: 15px 0;
            font-size: 16px;
        }
        .status.loading {
            background: #fff3cd;
            color: #856404;
            border: 2px solid #ffc107;
        }
        .status.success {
            background: #d4edda;
            color: #155724;
            border: 2px solid #28a745;
        }
        .status.error {
            background: #f8d7da;
            color: #721c24;
            border: 2px solid #dc3545;
        }
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 15px 40px;
            border-radius: 10px;
            font-size: 18px;
            cursor: pointer;
            width: 100%;
            margin-top: 20px;
            font-weight: bold;
            transition: transform 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        .stats {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-top: 20px;
        }
        .stat-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #dee2e6;
        }
        .stat-item:last-child {
            border-bottom: none;
        }
        .stat-label {
            font-weight: bold;
            color: #495057;
        }
        .stat-value {
            color: #667eea;
        }
        .warning {
            background: #fff3cd;
            border: 2px solid #ffc107;
            padding: 15px;
            border-radius: 10px;
            margin-top: 20px;
            font-size: 14px;
            color: #856404;
        }
        .icon {
            font-size: 48px;
            text-align: center;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">📥</div>
        <h1>استيراد البيانات</h1>
        <p class="subtitle">استيراد البيانات من crm_data.json إلى localStorage</p>
        
        <div id="status"></div>
        
        <button id="importBtn" class="btn">🚀 بدء الاستيراد</button>
        
        <div id="stats" class="stats" style="display: none;"></div>
        
        <div class="warning">
            ⚠️ <strong>مهم:</strong> بعد الاستيراد، أغلق هاد الصفحة و افتح الصفحة الرئيسية.
        </div>
    </div>

    <script>
        const statusDiv = document.getElementById('status');
        const importBtn = document.getElementById('importBtn');
        const statsDiv = document.getElementById('stats');

        function showStatus(message, type) {
            statusDiv.className = 'status ' + type;
            statusDiv.textContent = message;
        }

        function showStats(data) {
            const orders = data.afrizon_orders_v5?.d || [];
            const users = data.afrizon_users_v1?.d || [];
            const cities = data.afrizon_villes_v2?.d || [];
            const chat = data.afrizon_chat_v1?.d || [];
            const adspend = data.afrizon_adspend_v1?.d || [];
            const catalog = data.afrizon_catalog_v1?.d || [];
            const agents = data.afrizon_agent_names_v1?.d || [];

            statsDiv.innerHTML = `
                <h3 style="margin-bottom: 15px; color: #667eea;">📊 البيانات المستوردة:</h3>
                <div class="stat-item">
                    <span class="stat-label">الطلبات:</span>
                    <span class="stat-value">${orders.length} طلب</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">المستخدمين:</span>
                    <span class="stat-value">${users.length} مستخدم</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">المدن:</span>
                    <span class="stat-value">${cities.length} مدينة</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">المحادثات:</span>
                    <span class="stat-value">${chat.length} رسالة</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">الإعلانات:</span>
                    <span class="stat-value">${adspend.length} إعلان</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">المنتجات:</span>
                    <span class="stat-value">${catalog.length} منتج</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">الوكلاء:</span>
                    <span class="stat-value">${agents.length} وكيل</span>
                </div>
            `;
            statsDiv.style.display = 'block';
        }

        async function importData() {
            importBtn.disabled = true;
            showStatus('⏳ جاري تحميل البيانات من crm_data.json...', 'loading');

            try {
                // Fetch data from crm_data.json
                const response = await fetch('api.php?t=' + Date.now(), { cache: 'no-store', headers: { 'X-Sync-Token': '8c907fc0f4ffe0b9775a6b7c3c0fc7700e5724c0d78343df' } });
                if (!response.ok) {
                    throw new Error('ما لقيناش crm_data.json');
                }

                const data = await response.json();
                showStatus('⏳ جاري حفظ البيانات في localStorage...', 'loading');

                // Import all keys to localStorage
                let count = 0;
                // كل مفتاح فـ crm_data.json مغلف بـ {t, d}: كنخزنو غير d فـ localStorage
                // (قبل كان كيتخزن الغلاف كامل → داتا مخربقة {t,d:{t,d}} كتتزامن للسيرفر)
                const unwrap = v => { let g = 0; while (v && typeof v === 'object' && !Array.isArray(v) && typeof v.t === 'number' && 'd' in v && Object.keys(v).length <= 2 && g++ < 5) v = v.d; return v; };
                for (const key in data) {
                    if (data.hasOwnProperty(key)) {
                        if (key === 'afrizon_backup_v1' || key === 'afrizon_backup_v1_agents') continue;
                        const env = data[key];
                        let payload = (env && typeof env === 'object' && 't' in env && 'd' in env) ? unwrap(env.d) : env;
                        if (key === 'afrizon_history_v1' && Array.isArray(payload) && payload.length > 400) {
                            payload = payload.slice(0, 400);
                        }
                        try {
                            localStorage.setItem(key, JSON.stringify(payload));
                            if (env && typeof env.t === 'number') localStorage.setItem('ct_' + key, String(env.t));
                            count++;
                        } catch(e) {
                            console.warn("Storage quota error during import for key:", key, e);
                        }
                    }
                }

                showStatus(`✅ تم استيراد ${count} مفتاح بنجاح!`, 'success');
                showStats(data);

                // Redirect to main page after 3 seconds
                setTimeout(() => {
                    window.location.href = '/';
                }, 3000);

            } catch (error) {
                showStatus('❌ خطأ: ' + error.message, 'error');
                importBtn.disabled = false;
            }
        }

        importBtn.addEventListener('click', importData);
    </script>
</body>
</html>