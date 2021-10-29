<?php
/**
 * 基于ssh自身socket的客户端
 * @author chenyansheng
 * @date 2021年10月27日
 */
namespace Chenyansheng\PhpSshSock;

use Closure;
use Symfony\Component\Process\Process;
use Exception;


class SSHSock {
    protected string $host;
    protected string $ip;
    protected string $user;
    protected int $port;
    protected int $conn_timeout;
    protected bool $enable_strict_host_check = false;
    protected string $private_key = '';
    protected int $sock_alive_time = 120;
    
    protected Closure $processConfigurationClosure;
    protected Closure $onOutput;
    
    
    public function __construct(string $ip, string $user='root', int $port=22, int $conn_timeout=10) {
        $this->ip = $ip;
        $this->user = $user;
        $this->port = $port;
        $this->conn_timeout = $conn_timeout;
        
        $this->processConfigurationClosure = fn(Process $process) => null;
        $this->onOutput = fn($type, $line) => null;
        
        $this->host = "{$this->user}@{$this->ip}";
    }
    
    public function configureProcess(Closure $processConfigurationClosure): self {
        $this->processConfigurationClosure = $processConfigurationClosure;
        return $this;
    }
    
    public function onOutput(Closure $onOutput): self {
        $this->onOutput = $onOutput;
        return $this;
    }
    
    /**
     * 允许检测known_host
     */
    public function enableStrictHostKeyChecking(): self {
        $this->enable_strict_host_check = true;
        return $this;
    }
    
    /**
     * 指定私钥
     */
    public function usePrivateKey(string $private_key): self {
        $this->private_key = $private_key;
        return $this;
    }
    
    /**
     * 指定sock存活时间
     */
    public function setSockAliveTime($time) {
        $this->sock_alive_time = $time;
    }

    /**
     * 获取执行的命令
     * @param string $command
     * @return string
     */
    public function getExecuteCommand($command): string {
        $options = $this->getSSHOptions();
        $options2 = sprintf("%s -o ControlPath=~/.ssh/ssh_%s.sock", $options, $this->host);
        $delimiter = 'EOF-SPATIE-SSH';
        $cmd = "ssh {$options2} {$this->host} 'bash -se' << \\$delimiter" . PHP_EOL
            . $command . PHP_EOL
            . $delimiter;
        
        echo $cmd . "<br>";
        return $cmd;
    }
    
    /**
     * 执行
     * @param string $command
     * @return \Symfony\Component\Process\Process
     */
    public function execute($command): Process {
        $this->createSock();
        $ssh_cmd = $this->getExecuteCommand($command);
        return $this->run($ssh_cmd);
    }
    
    /**
     * 异步执行
     * @param string $command
     * @return \Symfony\Component\Process\Process
     */
    public function executeAsync($command): Process {
        $this->createSock();
        $ssh_cmd = $this->getExecuteCommand($command);
        return $this->run($ssh_cmd, 'start');
    }
    
    /**
     * 执行
     * @return \Symfony\Component\Process\Process
     */
    protected function run(string $command, string $method = 'run'): Process {
        $process = Process::fromShellCommandline($command);
        $process->setTimeout(0);
        ($this->processConfigurationClosure)($process);
        $process->{$method}($this->onOutput);
        return $process;
    }
    
    /**
     * 获取ssh选项
     * @return string
     */
    private function getSSHOptions(): string {
        $options = [];
        if($this->port) {
            $options[] = "-p {$this->port}";
        }
        if($this->conn_timeout) {
            $options[] = "-o ConnectTimeout={$this->conn_timeout}";
        }
        if($this->private_key) {
            $options[] = "-i {$this->private_key}";
        }
        if( ! $this->enable_strict_host_check ) {
            $options[] = "-o StrictHostKeyChecking=no";
            $options[] = "-o UserKnownHostsFile=/dev/null";
        }
        return implode(' ', $options);
    }
    
    /*
     * 创建sock
     */
    private function createSock() {
        if($this->checkExistsSock()) {
            return true;
        } else {
            $options = $this->getSSHOptions();
            $cmd = sprintf('ssh %s -o ControlMaster=auto -o ControlPath=~/.ssh/ssh_%s.sock -o ControlPersist=%d %s "exit 0"', $options, $this->host, $this->sock_alive_time, $this->host);
            return $this->execCMD($cmd);
        }
    }
    
    /*
     * 检测sock是否存活，如果有僵死进程顺路kill
     */
    private function checkExistsSock() {
        $options = $this->getSSHOptions();
        $cmd = sprintf('ssh %s -o ControlPath=~/.ssh/ssh_%s.sock -O check %s 2>&1', $options, $this->host, $this->host);
        echo $cmd . "<br>";
        $output = $return_var = null;
        exec($cmd, $output, $return_var);
        if($return_var !== 0) {
            if(strpos(implode(" ", $output), "Connection refused") !== false) {  //存在僵死进程
                $this->killSock();
            }
            return false;
        }
        return true;
    }
    
    /*
     * 杀进程
     */
    private function killSock() {
        $cmd = sprintf('find ~/.ssh -name "ssh_%s.sock" -exec rm -f {} \;', $this->host);
        $this->execCMD($cmd);
        $cmd2 = sprintf('pid=`pgrep -f "ssh_%s.sock"`;[ -n "$pid" ] && kill $pid', $this->host);
        $this->execCMD($cmd2);
    }
    
    /*
     * exec执行命令
     */
    private function execCMD($cmd) {
        if(strpos($cmd, "2>&1") !== false) {
            $cmd .= ' 2>&1';
        }
        echo $cmd . "<br>";
        $output = $return_var = null;
        exec($cmd, $output, $return_var);
        if($return_var !== 0) {
            throw new Exception('fail run exec: ' . $cmd . ", return: " . implode("\n", $output));
        }
        return true;
    }
}
