#!/bin/bash
S=/tmp/claude-1000/-home-denis--rea-de-trabalho-TCC/6b104e01-2d11-4413-b49a-78a97813f5d9/scratchpad
CH=$HOME/.cache/ms-playwright/chromium-1248/chrome-linux64/chrome
$CH --headless=new --ignore-certificate-errors --no-sandbox --remote-debugging-port=9333 --user-data-dir=$S/qual/chdata about:blank >/dev/null 2>&1 & CHPID=$!
until curl -s localhost:9333/json/version >/dev/null; do sleep 1; done
SID=$($S/venv-carga/bin/python -c "
import requests,urllib3;urllib3.disable_warnings()
s=requests.Session();s.verify=False;s.post('https://127.0.0.1:38443/api/login',json={'email':'vitima@teste.dev','password':'Seg@2026xx'});print(s.cookies.get('PHPSESSID'))")
for alvo in "inicio|/|" "login|/login|" "projeto|/projeto?id=30|$SID" "grafico|/ver-projeto?id=30|$SID"; do
  IFS='|' read n u sid <<< "$alvo"
  for modo in desktop mobile; do
    args=(https://127.0.0.1:38443$u --port=9333 --quiet --only-categories=performance,accessibility,best-practices,seo --output=json --output-path=/w/qual/lh/$n-$modo.json)
    [ $modo = desktop ] && args+=(--preset=desktop)
    [ -n "$sid" ] && args+=("--extra-headers={\"Cookie\":\"PHPSESSID=$sid\"}")
    timeout 240 docker run --rm --network host -v $S:/w -w /w/pw node:20-alpine node node_modules/lighthouse/cli/index.js "${args[@]}" >/dev/null 2>&1
    python3 -c "
import json;d=json.load(open('$S/qual/lh/$n-$modo.json'));c=d['categories'];a=d['audits']
print('$n'.ljust(8),'$modo'.ljust(8),' '.join(f\"{k[:4]}={round((v['score'] or 0)*100)}\" for k,v in c.items()),'| LCP',a['largest-contentful-paint']['displayValue'],'| TBT',a['total-blocking-time']['displayValue'],'| CLS',a['cumulative-layout-shift']['displayValue'])" 2>&1 | tail -1
  done
done
kill $CHPID
