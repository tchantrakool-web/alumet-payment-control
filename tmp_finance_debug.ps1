$base='http://localhost/Alumet/alumet-payment-control'
$work=Join-Path (Get-Location) '.tmp_finance_debug'
if(Test-Path $work){Remove-Item $work -Recurse -Force}
New-Item -ItemType Directory -Path $work | Out-Null
$cookie=Join-Path $work 'finance.cookie.txt'; Set-Content $cookie ''
function Enc([string]$s){[uri]::EscapeDataString($s)}
& curl.exe -sS -D (Join-Path $work 'login.h.txt') -o (Join-Path $work 'login.b.txt') -b $cookie -c $cookie -H 'Content-Type: application/x-www-form-urlencoded' --data "username=$(Enc 'finance1')&password=$(Enc 'finance1234')" "$base/login.php" | Out-Null
& curl.exe -sS -D (Join-Path $work 'detail.h.txt') -o (Join-Path $work 'detail.b.txt') -b $cookie -c $cookie "$base/modules/payment_requests/detail.php?id=2" | Out-Null
Get-Content (Join-Path $work 'login.h.txt') -Raw
