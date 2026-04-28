#!/bin/bash

TOKEN="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1c2VyX25hbWUiOiIyMDgwNCIsImF1dGhvcml0aWVzIjpbXSwiY2xpZW50X2lkIjoiZnJvbnRfb2ZmaWNlIiwiZG9jdW1lbnRJZHMiOm51bGwsInVzZXJfdHlwZSI6IklORElWSURVQUwiLCJ1c2VyX2lkIjoyMDgwNCwidXNlcl9pbmZvIjp7ImlkIjoyMDgwNCwicGluIjoiNTE2MTAwMjc0NDAwMjIiLCJmaXJzdF9uYW1lIjoiTlVSQkVLIiwibGFzdF9uYW1lIjoiSlVNTUFZRVYiLCJtaWRkbGVfbmFtZSI6IlVMVUfigJhCRVJESSBP4oCYR-KAmExJIiwicGhvdG8iOm51bGx9LCJzY29wZSI6WyJyZWFkIiwid3JpdGUiXSwib3JnYW5pemF0aW9uIjpudWxsLCJleHBlcnRfc3RhdHVzIjpudWxsLCJsZWdhbF9lbnRpdHkiOnsidGluIjpudWxsLCJuYW1lIjoiIn0sImV4cCI6MTc3NzQ0MTAxMSwiZGVwYXJ0bWVudCI6bnVsbCwianRpIjoiNjVkZjM0YzUtMjg3YS00N2I2LTkwZDUtOGZkODVhZDczYTg3In0._mX2QXgg06zUktLUnCFzExsANRH4DKu-ECR4bBskglc"

check_api() {
    STATUS=$(curl -s -o /dev/null -w "%{http_code}" -X POST \
        'https://api-ip.adliya.uz/v1/register/public/search?objectType=TRADEMARK' \
        -H "Authorization: Bearer $TOKEN" \
        -H 'Content-Type: application/json' \
        -H 'Referer: https://im.adliya.uz/' \
        --max-time 30 \
        -d '{"size":1,"page":0,"sort":{"name":"number","direction":"desc"},"lang":"uz","search":[{"key":"APPLICATION__application_status","value":"COMPLETED","operation":"EQUALITY","prefix":"APPLICATION"}],"response_data":[]}')

    echo $STATUS
}

echo "Rate limit tekshirish boshlandi..."
echo "Har 30 daqiqada tekshiriladi. Ctrl+C - to'xtatish"
echo ""

while true; do
    NOW=$(date "+%Y-%m-%d %H:%M:%S")
    STATUS=$(check_api)

    if [ "$STATUS" == "200" ]; then
        echo "[$NOW] ✅ API tayyor! Status: $STATUS"
        echo ""
        echo "Endi commandni ishga tushirishingiz mumkin:"
        echo "php artisan trademarks:fetch --skip-existing --delay=5000"

        # Notification (macOS)
        osascript -e 'display notification "API rate limit resetlandi!" with title "Trademark Fetcher"' 2>/dev/null

        break
    else
        echo "[$NOW] ⏳ Hali bloklangan. Status: $STATUS"
    fi

    # 30 daqiqa kutish
    sleep 1800
done