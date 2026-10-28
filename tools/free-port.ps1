param(
  [int]$Start = 8080,
  [int]$Count = 200
)

$addresses = @([System.Net.IPAddress]::Loopback)

if ([System.Net.Sockets.Socket]::OSSupportsIPv6) {
  $addresses += [System.Net.IPAddress]::IPv6Loopback
}

for ($port = $Start; $port -lt ($Start + $Count); $port++) {
  $free = $true

  foreach ($address in $addresses) {
    $listener = $null

    try {
      $listener = [System.Net.Sockets.TcpListener]::new($address, $port)
      $listener.Start()
    } catch {
      $free = $false
    } finally {
      if ($null -ne $listener) {
        try { $listener.Stop() } catch { }
      }
    }

    if (-not $free) {
      break
    }
  }

  if ($free) {
    Write-Output $port
    exit 0
  }
}

exit 1
