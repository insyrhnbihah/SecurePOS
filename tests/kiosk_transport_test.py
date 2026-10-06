"""Read-only transport policy tests; no pairing, token or database writes."""
from pathlib import Path
import os,subprocess,urllib.request,urllib.error
ROOT=Path(__file__).resolve().parents[1]
PHP=r'C:\xampp\php\php.exe'
cases=[('local','localhost','127.0.0.1',False,True),('local','127.0.0.1','127.0.0.1',False,True),('local','localhost:8080','::1',False,True),('local','example.test','127.0.0.1',False,False),('local','localhost.example.test','127.0.0.1',False,False),('local','localhost','192.168.1.20',False,False),('production','localhost','127.0.0.1',False,False),('production','127.0.0.1','127.0.0.1',False,False),('production','example.test','203.0.113.2',False,False),('production','example.test','203.0.113.2',True,True),('local','example.test','203.0.113.2',True,True)]
code="$_SERVER=['HTTP_HOST'=>$argv[1],'REMOTE_ADDR'=>$argv[2],'SERVER_ADDR'=>'127.0.0.1','HTTPS'=>$argv[3],'SERVER_PORT'=>$argv[3]==='on'?'443':'80']; require 'config/attendance_qr.php'; echo attendanceKioskTransportAllowed()?'allowed':'denied';"
for environment,host,remote,https,allowed in cases:
 env=os.environ.copy();env['SECUREPOS_ENV']=environment
 r=subprocess.run([PHP,'-r',code,host,remote,'on' if https else 'off'],cwd=ROOT,env=env,capture_output=True)
 assert r.returncode==0 and not r.stderr and r.stdout==(b'allowed' if allowed else b'denied'),(environment,host,remote,r.stdout,r.stderr)
 print('PASS:',environment,host,'HTTPS' if https else 'HTTP','allowed' if allowed else 'denied')
for host in ['localhost','127.0.0.1']:
 with urllib.request.urlopen('http://'+host+'/SecurePOS/attendance_kiosk.php',timeout=15) as response:
  body=response.read(); assert response.status==200 and 'pair=1' in response.url and b'Attendance display requires HTTPS.' not in body
  print('PASS: actual local HTTP kiosk access reaches pairing page on',host)
 try: urllib.request.urlopen('http://'+host+'/SecurePOS/attendance_kiosk_qr.php',timeout=15)
 except urllib.error.HTTPError as error:
  assert error.code==401
  print('PASS: unpaired local QR access still rejected on',host)
 else: raise AssertionError('Unpaired QR access unexpectedly allowed')
request=urllib.request.Request('http://127.0.0.1/SecurePOS/attendance_kiosk.php',headers={'Host':'example.test'})
try: urllib.request.urlopen(request,timeout=15)
except urllib.error.HTTPError as error: assert error.code==400
else: raise AssertionError('Non-local Host allowed HTTP')
print('PASS: non-local HTTP Host remains blocked; no database mutations performed')
