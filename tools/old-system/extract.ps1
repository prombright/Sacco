# Reads SMACON's old accounting system (Accounts.mdb) and writes what the import needs as JSON.
# Read-only. Keep the output outside the repository: it holds members' money details.
#
#   $env:SMACON_MDB_PASSWORD = '...'
#   .\extract.ps1 -Mdb 'C:\path\Accounts.mdb' -CutOff 2026-08-31 -OutDir 'C:\somewhere\data'
param(
  [Parameter(Mandatory)] [string] $Mdb,
  [Parameter(Mandatory)] [string] $CutOff,
  [Parameter(Mandatory)] [string] $OutDir
)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Web.Extensions
if (-not $env:SMACON_MDB_PASSWORD) { throw 'Set SMACON_MDB_PASSWORD first.' }
New-Item -ItemType Directory -Force $OutDir | Out-Null

$c = New-Object System.Data.OleDb.OleDbConnection "Provider=Microsoft.ACE.OLEDB.12.0;Data Source=$Mdb;Mode=Read;Jet OLEDB:Database Password=$env:SMACON_MDB_PASSWORD;"
$c.Open()
$cut = "#$CutOff#"
$serializer = New-Object System.Web.Script.Serialization.JavaScriptSerializer
$serializer.MaxJsonLength = [int]::MaxValue

function Save($name, $sql) {
  $cmd = $c.CreateCommand(); $cmd.CommandText = $sql; $cmd.CommandTimeout = 300
  $da = New-Object System.Data.OleDb.OleDbDataAdapter $cmd
  $t = New-Object System.Data.DataTable; [void]$da.Fill($t)
  $names = @($t.Columns | ForEach-Object ColumnName)
  $list = New-Object System.Collections.ArrayList
  foreach ($r in $t.Rows) {
    $o = @{}
    foreach ($n in $names) {
      $v = $r[$n]
      if ($v -is [DBNull]) { $v = $null } elseif ($v -is [datetime]) { $v = $v.ToString('yyyy-MM-dd') }
      $o[$n] = $v
    }
    [void]$list.Add($o)
  }
  [System.IO.File]::WriteAllText((Join-Path $OutDir "$name.json"), $serializer.Serialize($list), [System.Text.UTF8Encoding]::new($false))
  "$name : $($list.Count)"
}

Save 'clients' 'SELECT ClientCode, Surname, OtherNames, Gender, Telephone FROM ClientInfo'
Save 'ledger' "SELECT MemberId, [Date] AS d, paydet, vno, [Cr OptSave]-[Db OptSave] AS sv, [Cr share]-[Db share] AS sh, [Db Loan]-[Cr Loan] AS ln, [Db LoanInt]-[Cr LoanInt] AS li, [Cr SavingsInt]-[Db SavingsInt] AS si, ([Cr LPF]-[Db LPF])+([Cr AnnualSub]-[Db AnnualSub])+([Cr Pension]-[Db Pension])+([Cr Penalty]-[Db Penalty]) AS ot, [Cr Pension]-[Db Pension] AS pn, [Cr AnnualSub]-[Db AnnualSub] AS sb FROM transloan WHERE [Date]<=$cut"
Save 'plan' 'SELECT MemberId, MLoan FROM mondata'
Save 'loans' "SELECT MEMBERID, LOANID, TDATE, InterestRate, CurrentLoan FROM LoanInfo WHERE TDATE<=$cut"
Save 'gl' "SELECT t.ACODE, MAX(i.ANAME) AS name, MAX(i.ATYPECD) AS type, SUM(IIF(t.TTYPE='D',t.AMOUNT,-t.AMOUNT)) AS net FROM TRANS t LEFT JOIN INIT i ON t.ACODE=i.ACODE WHERE t.[DATE]<=$cut GROUP BY t.ACODE"
$c.Close()
