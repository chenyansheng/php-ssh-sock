<?php
use Chenyansheng\PhpSshSock\SSHSock;
require __DIR__ . '/vendor/autoload.php';


//测试时把ip改成自己服务器ip,并且在服务器上面配置好ssh登陆权限
$ssh = new \Chenyansheng\PhpSshSock\SSHSock('192.168.1.111');
$cmd = 'ifconfig';
//echo $ssh->getExecuteCommand($cmd);

$process = $ssh->execute($cmd);
echo "<br>============<br>";
if($process->isSuccessful()) {
    $output = $process->getOutput();
    echo $output;
} else {
    $error_output = $process->getErrorOutput();
    echo "[error]" . $error_output;
}
