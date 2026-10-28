; Windows client installer for the PHP + MySQL app described in app.ini (Inno Setup 6).
; Build it with build.bat / build.ps1. setup.exe carries only the application. On the client PC:
;  - it refuses to run over the same or a newer installed version (/REPAIR reinstalls the same one);
;  - a new install, or an upgrade whose PHP / MySQL line changed, downloads the latest Apache 2.4,
;    PHP and MySQL patches and the VC++ runtime (needs internet) and sets them up as services;
;  - any other upgrade backs up the database, replaces only the app code and restarts Apache;
;  - with [license] enabled=1 it needs a license key issued for this computer's Machine ID.
; The database, config\, backups\, the app's settings file and its "keep" folders survive upgrades.

#define IniFile SourcePath + "app.ini"
#define AppName ReadIni(IniFile, "app", "name", "")
#define AppGuid ReadIni(IniFile, "app", "id", "")
#define ShortName ReadIni(IniFile, "app", "short_name", "")
#define Publisher ReadIni(IniFile, "app", "publisher", AppName)
#define DefaultPort ReadIni(IniFile, "app", "default_port", "80")
#define PhpLine ReadIni(IniFile, "requires", "php", "")
#define MySqlLine ReadIni(IniFile, "requires", "mysql", "")
#define LicenseEnabled ReadIni(IniFile, "license", "enabled", "0")
#ifndef AppVersion
  #define AppVersion ReadIni(IniFile, "release", "version", "0.0.0")
#endif
#ifndef Stage
  #define Stage ".stage"
#endif

[Setup]
AppId={{{#AppGuid}}
AppName={#AppName}
AppVersion={#AppVersion}
AppVerName={#AppName} {#AppVersion}
AppPublisher={#Publisher}
VersionInfoVersion={#AppVersion}
DefaultDirName={sd}\{#ShortName}
UsePreviousAppDir=yes
DisableDirPage=auto
DisableProgramGroupPage=yes
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
MinVersion=10.0
OutputDir=output
OutputBaseFilename={#ShortName}-Setup-{#AppVersion}
SetupIconFile={#Stage}\app.ico
UninstallDisplayIcon={app}\installer\app.ico
UninstallDisplayName={#AppName}
Compression=lzma2/ultra64
SolidCompression=yes
WizardStyle=modern
SetupLogging=yes
CloseApplications=no

[Tasks]
Name: firewall; Description: "Allow other computers on this network to open {#AppName}"; Flags: unchecked

[InstallDelete]
; Re-extracted from the downloads when the server components are (re)installed. The old app code is
; removed by pre-upgrade.ps1, which keeps the settings file and the "keep" folders.
Type: filesandordirs; Name: "{app}\apache"; Check: FullInstallNeeded
Type: filesandordirs; Name: "{app}\php"; Check: FullInstallNeeded
Type: filesandordirs; Name: "{app}\mysql"; Check: FullInstallNeeded
Type: filesandordirs; Name: "{app}\installer"

[Files]
Source: "{#Stage}\app\*"; DestDir: "{app}\app"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#Stage}\keep\*"; DestDir: "{app}\app"; Flags: onlyifdoesntexist uninsneveruninstall recursesubdirs createallsubdirs skipifsourcedoesntexist
Source: "app.ini"; DestDir: "{app}\installer"; Flags: ignoreversion
Source: "scripts\common.ps1"; DestDir: "{app}\installer"; Flags: ignoreversion
Source: "scripts\configure.ps1"; DestDir: "{app}\installer"; Flags: ignoreversion
Source: "scripts\backup.ps1"; DestDir: "{app}\installer"; Flags: ignoreversion
Source: "scripts\uninstall.ps1"; DestDir: "{app}\installer"; Flags: ignoreversion
Source: "{#Stage}\app.ico"; DestDir: "{app}\installer"; Flags: ignoreversion
Source: "scripts\resolve.ps1"; Flags: dontcopy
Source: "scripts\port-check.ps1"; Flags: dontcopy
Source: "scripts\pre-upgrade.ps1"; Flags: dontcopy
#if LicenseEnabled == "1"
; Built by build.ps1 with the public key embedded.
Source: "{#Stage}\license-check.php"; DestDir: "{app}\installer"; Flags: ignoreversion
Source: "scripts\machine-id.ps1"; DestDir: "{app}\installer"; Flags: ignoreversion
Source: "scripts\license.ps1"; Flags: dontcopy
Source: "license\public-key.xml"; Flags: dontcopy
#endif

[UninstallRun]
Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\installer\uninstall.ps1"" -InstallDir ""{app}"""; Flags: runhidden waituntilterminated; RunOnceId: "RemoveServices"

[UninstallDelete]
Type: filesandordirs; Name: "{app}\apache"
Type: filesandordirs; Name: "{app}\php"
Type: filesandordirs; Name: "{app}\mysql"
Type: filesandordirs; Name: "{app}\installer"

[Run]
Filename: "{code:GetAppUrl}"; Description: "Open {#AppName}"; Flags: postinstall shellexec nowait; Check: ConfigureSucceeded

[Code]
const
  Components = 'php,apache,mysql,vcredist,cacert';
  UninstallKey = 'Software\Microsoft\Windows\CurrentVersion\Uninstall\{' + '{#AppGuid}' + '}_is1';
  LicenseEnabled = {#LicenseEnabled};

var
  ServerPage, LicensePage: TInputQueryWizardPage;
  DownloadPage: TDownloadWizardPage;
  ConfigureOk, RepairMode, FullInstallKnown, FullInstall, LicenseChecked, InstalledLicenseOk: Boolean;
  InstalledVersion, MachineId, LicenseKey: String;

function AppDir(): String;
begin
  Result := AddBackslash(WizardDirValue);
end;

function InstallIni(): String;
begin
  Result := AppDir + 'config\install.ini';
end;

function IsUpgrade(): Boolean;
begin
  Result := InstalledVersion <> '';
end;

function HttpPort(): String;
begin
  Result := Trim(ServerPage.Values[0]);
  if Result = '' then
  begin
    Result := '{#DefaultPort}';
    if FileExists(InstallIni) then
      Result := GetIniString('install', 'http_port', '{#DefaultPort}', InstallIni);
  end;
end;

function RunScript(Script, Args: String): Boolean;
var
  Code: Integer;
begin
  ExtractTemporaryFile(Script);
  Result := Exec('powershell.exe', '-NoProfile -ExecutionPolicy Bypass -File "' + ExpandConstant('{tmp}\') + Script + '" ' + Args,
    '', SW_HIDE, ewWaitUntilTerminated, Code) and (Code = 0);
end;

function HasParam(Name: String): Boolean;
var
  I: Integer;
begin
  Result := False;
  for I := 1 to ParamCount do
    if CompareText(ParamStr(I), Name) = 0 then
      Result := True;
end;

// Takes the next number off a dotted version string.
function NextVersionPart(var S: String): Integer;
var
  P: Integer;
begin
  P := Pos('.', S);
  if P = 0 then
  begin
    Result := StrToIntDef(S, 0);
    S := '';
  end
  else
  begin
    Result := StrToIntDef(Copy(S, 1, P - 1), 0);
    Delete(S, 1, P);
  end;
end;

// -1, 0 or 1 as A is older than, equal to or newer than B.
function CompareVersions(A, B: String): Integer;
var
  I, X, Y: Integer;
begin
  Result := 0;
  for I := 1 to 4 do
  begin
    X := NextVersionPart(A);
    Y := NextVersionPart(B);
    if X <> Y then
    begin
      if X > Y then Result := 1 else Result := -1;
      Exit;
    end;
  end;
end;

function InitializeSetup(): Boolean;
var
  Cmp: Integer;
begin
  Result := True;
  RepairMode := HasParam('/REPAIR');
  if not RegQueryStringValue(HKLM64, UninstallKey, 'DisplayVersion', InstalledVersion) then
    InstalledVersion := '';
  if not IsUpgrade or RepairMode then
    Exit;
  // Builds before version numbering were stamped yyyy.m.d.hhmm: treat them as older than any release.
  if StrToIntDef(Copy(InstalledVersion, 1, Pos('.', InstalledVersion) - 1), 0) >= 2000 then
    Cmp := -1
  else
    Cmp := CompareVersions(InstalledVersion, '{#AppVersion}');
  if Cmp = 0 then
  begin
    SuppressibleMsgBox('{#AppName} {#AppVersion} is already installed on this computer, so there is nothing to update.' + #13#10#13#10 +
      'To repair the installation, run this setup with /REPAIR.', mbInformation, MB_OK, IDOK);
    Result := False;
  end
  else if Cmp > 0 then
  begin
    SuppressibleMsgBox('A newer version of {#AppName} (' + InstalledVersion + ') is already installed on this computer.' + #13#10#13#10 +
      'This setup is version {#AppVersion} and cannot downgrade it.', mbError, MB_OK, IDOK);
    Result := False;
  end;
end;

// ---- Server components ---------------------------------------------------------------

// True when Apache 2.4, PHP and MySQL of the required lines are installed and set up in the target folder.
function ComponentsInstalled(): Boolean;
begin
  Result := FileExists(InstallIni) and FileExists(AppDir + 'config\secrets.ini') and
    FileExists(AppDir + 'php\php.exe') and FileExists(AppDir + 'apache\bin\httpd.exe') and FileExists(AppDir + 'mysql\bin\mysqld.exe') and
    (Pos('{#PhpLine}.', GetIniString('install', 'php', '', InstallIni)) = 1) and
    (Pos('{#MySqlLine}.', GetIniString('install', 'mysql', '', InstallIni)) = 1) and
    (Pos('2.4.', GetIniString('install', 'apache', '', InstallIni)) = 1);
end;

// Fixed once the user confirms the Ready page: files change after that.
function FullInstallNeeded(): Boolean;
begin
  if FullInstallKnown then
    Result := FullInstall
  else
    Result := RepairMode or not ComponentsInstalled;
end;

// Finds the latest component versions and downloads them into {tmp}, checking SHA-256 where the vendor publishes one.
function DownloadComponents(): Boolean;
var
  ComponentsIni: String;
  Names: TStringList;
  I: Integer;
begin
  Result := False;
  ComponentsIni := ExpandConstant('{tmp}\components.ini');
  DownloadPage.SetText('Finding the latest versions...', '');
  DownloadPage.SetProgress(0, 0);
  DownloadPage.Show;
  try
    if not RunScript('resolve.ps1', '-OutFile "' + ComponentsIni + '" -PhpLine {#PhpLine} -MySqlLine {#MySqlLine}') then
    begin
      SuppressibleMsgBox('Could not look up the server components. Check the internet connection and try again.' + #13#10#13#10 +
        GetIniString('error', 'message', '', ComponentsIni), mbError, MB_OK, IDOK);
      Exit;
    end;
    DownloadPage.Clear;
    Names := TStringList.Create;
    try
      Names.CommaText := Components;
      for I := 0 to Names.Count - 1 do
        DownloadPage.Add(GetIniString(Names[I], 'url', '', ComponentsIni), GetIniString(Names[I], 'file', '', ComponentsIni),
          GetIniString(Names[I], 'sha256', '', ComponentsIni));
    finally
      Names.Free;
    end;
    try
      DownloadPage.Download;
      Result := True;
    except
      if not DownloadPage.AbortedByUser then
        SuppressibleMsgBox('Download failed: ' + AddPeriod(GetExceptionMessage) + #13#10#13#10 +
          'Check the internet connection and click Install again.', mbError, MB_OK, IDOK);
    end;
  finally
    DownloadPage.Hide;
  end;
end;

// ---- License ---------------------------------------------------------------------------

function LicenseIni(): String;
begin
  Result := ExpandConstant('{tmp}\license.ini');
end;

function InstalledLicenseFile(): String;
begin
  Result := AppDir + 'config\license.key';
end;

function RunLicense(Args: String): Boolean;
begin
  ExtractTemporaryFile('machine-id.ps1');
  ExtractTemporaryFile('public-key.xml');
  Result := RunScript('license.ps1', Args + ' -OutFile "' + LicenseIni + '" -PublicKeyFile "' + ExpandConstant('{tmp}\public-key.xml') + '"');
end;

// A key already saved in the target folder that is valid for this computer (reinstall / upgrade).
function InstalledLicenseValid(): Boolean;
var
  Key: AnsiString;
begin
  if not LicenseChecked then
  begin
    LicenseChecked := True;
    InstalledLicenseOk := FileExists(InstalledLicenseFile) and RunLicense('-Action verify -KeyFile "' + InstalledLicenseFile + '"');
    if InstalledLicenseOk and LoadStringFromFile(InstalledLicenseFile, Key) then
      LicenseKey := Trim(String(Key));
  end;
  Result := InstalledLicenseOk;
end;

procedure CopyMachineId(Sender: TObject);
var
  Code: Integer;
begin
  if MachineId <> '' then
    Exec(ExpandConstant('{cmd}'), '/C echo ' + MachineId + '| clip', '', SW_HIDE, ewWaitUntilTerminated, Code);
end;

procedure CreateLicensePage();
var
  CopyButton: TNewButton;
begin
  LicensePage := CreateInputQueryPage(wpWelcome, 'License', 'Activate {#AppName} on this computer',
    'Each license key works on one computer only. Send the Machine ID below to your {#AppName} supplier ' +
    '(Copy puts it on the clipboard), then enter the license key they send back.');
  LicensePage.Add('Machine ID of this computer:', False);
  LicensePage.Add('License key:', False);
  LicensePage.Edits[0].ReadOnly := True;
  LicensePage.Values[1] := ExpandConstant('{param:LICENSEKEY|}');

  CopyButton := TNewButton.Create(LicensePage);
  CopyButton.Parent := LicensePage.Surface;
  CopyButton.Caption := 'Copy';
  CopyButton.Width := ScaleX(75);
  LicensePage.Edits[0].Width := LicensePage.Edits[0].Width - CopyButton.Width - ScaleX(8);
  CopyButton.Left := LicensePage.Edits[0].Left + LicensePage.Edits[0].Width + ScaleX(8);
  CopyButton.Top := LicensePage.Edits[0].Top - ScaleY(1);
  CopyButton.Height := LicensePage.Edits[0].Height + ScaleY(2);
  CopyButton.OnClick := @CopyMachineId;
end;

function LicensePageNext(): Boolean;
var
  Key: String;
begin
  Result := False;
  Key := Trim(LicensePage.Values[1]);
  StringChangeEx(Key, '"', '', True);
  StringChangeEx(Key, ' ', '', True);
  if Key = '' then
    MsgBox('Enter the license key you received for this computer.', mbError, MB_OK)
  else if not RunLicense('-Action verify -Key "' + Key + '"') then
    MsgBox(GetIniString('license', 'message', 'The license key could not be checked.', LicenseIni), mbError, MB_OK)
  else
  begin
    LicenseKey := Key;
    Result := True;
  end;
end;

// ---- Wizard ----------------------------------------------------------------------------

procedure InitializeWizard;
begin
  CreateLicensePage;
  ServerPage := CreateInputQueryPage(wpSelectDir, 'Server settings', 'Web port and company name',
    '{#AppName} runs its own web server and database on this computer.');
  ServerPage.Add('Web port (80 is standard; use 8080 if 80 is busy):', False);
  ServerPage.Add('Company name (optional):', False);
  ServerPage.Values[0] := ExpandConstant('{param:HTTPPORT|}');
  ServerPage.Values[1] := ExpandConstant('{param:COMPANY|}');
  DownloadPage := CreateDownloadPage('Downloading server components', 'Apache, PHP, MySQL and the Microsoft VC++ runtime (about 300 MB).', nil);
end;

function ShouldSkipPage(PageID: Integer): Boolean;
begin
  Result := False;
  if PageID = LicensePage.ID then
    Result := (LicenseEnabled <> 1) or InstalledLicenseValid
  else if PageID = ServerPage.ID then
    // An upgrade keeps its port and company name.
    Result := IsUpgrade and not RepairMode and FileExists(InstallIni);
end;

procedure CurPageChanged(CurPageID: Integer);
begin
  if CurPageID = ServerPage.ID then
    ServerPage.Values[0] := HttpPort
  else if (CurPageID = LicensePage.ID) and (MachineId = '') then
  begin
    WizardForm.NextButton.Enabled := False;
    try
      if RunLicense('-Action machineid') then
        MachineId := GetIniString('license', 'machine_id', '', LicenseIni)
      else
        MsgBox(GetIniString('license', 'message', 'The Machine ID of this computer could not be read.', LicenseIni), mbError, MB_OK);
    finally
      WizardForm.NextButton.Enabled := True;
    end;
    LicensePage.Values[0] := MachineId;
  end;
end;

function UpdateReadyMemo(Space, NewLine, MemoUserInfoInfo, MemoDirInfo, MemoTypeInfo, MemoComponentsInfo, MemoGroupInfo, MemoTasksInfo: String): String;
begin
  if IsUpgrade then
    Result := 'Upgrade from ' + InstalledVersion + ' to {#AppVersion}' + NewLine + Space + 'The database is backed up to the backups folder first.'
  else
    Result := 'New installation of {#AppName} {#AppVersion}';
  Result := Result + NewLine + NewLine + 'Server components:' + NewLine + Space;
  if FullInstallNeeded then
    Result := Result + 'Download and install Apache 2.4, PHP {#PhpLine} and MySQL {#MySqlLine} (about 300 MB)'
  else
    Result := Result + 'Already installed: only the application files are replaced, then the web server restarts';
  if MemoDirInfo <> '' then
    Result := Result + NewLine + NewLine + MemoDirInfo;
  if MemoTasksInfo <> '' then
    Result := Result + NewLine + NewLine + MemoTasksInfo;
end;

function NextButtonClick(CurPageID: Integer): Boolean;
var
  Port: Integer;
begin
  Result := True;
  if (CurPageID = LicensePage.ID) and (LicenseEnabled = 1) then
    Result := LicensePageNext
  else if (CurPageID = wpSelectDir) and (Pos(' ', WizardDirValue) > 0) then
  begin
    MsgBox('Please choose a folder without spaces, for example C:\{#ShortName}.', mbError, MB_OK);
    Result := False;
  end
  else if CurPageID = ServerPage.ID then
  begin
    Port := StrToIntDef(HttpPort, 0);
    if (Port < 1) or (Port > 65535) then
    begin
      MsgBox('Enter a web port between 1 and 65535.', mbError, MB_OK);
      Result := False;
    end
    else if not RunScript('port-check.ps1', '-Port ' + HttpPort + ' -InstallDir "' + WizardDirValue + '"') then
    begin
      MsgBox('Port ' + HttpPort + ' is already used by another program (often IIS, Skype or XAMPP). Enter a different port, such as 8080.', mbError, MB_OK);
      Result := False;
    end;
  end
  else if CurPageID = wpReady then
  begin
    FullInstall := FullInstallNeeded;
    FullInstallKnown := True;
    if FullInstall then
      Result := DownloadComponents;
  end;
end;

// Backs up the database and removes the old app code before anything is replaced. The running
// services lock the files an upgrade replaces.
function PrepareToInstall(var NeedsRestart: Boolean): String;
var
  Code: Integer;
  Args: String;
begin
  Result := '';
  // A silent install (/LICENSEKEY=...) never passes the License page.
  if (LicenseEnabled = 1) and (LicenseKey = '') and not InstalledLicenseValid and
    not ((Trim(LicensePage.Values[1]) <> '') and LicensePageNext) then
  begin
    Result := 'A license key for this computer is required. Run setup again and enter it (or pass /LICENSEKEY=...).';
    Exit;
  end;
  if IsUpgrade and DirExists(AppDir + 'app') then
  begin
    ExtractTemporaryFile('common.ps1');
    ExtractTemporaryFile('app.ini');
    Args := '-InstallDir "' + WizardDirValue + '" -FromVersion "' + InstalledVersion + '" -ToVersion "{#AppVersion}"';
    if not FileExists(AppDir + 'config\mysql-root.cnf') then
      Args := Args + ' -SkipBackup';
    ExtractTemporaryFile('pre-upgrade.ps1');
    Exec('powershell.exe', '-NoProfile -ExecutionPolicy Bypass -File "' + ExpandConstant('{tmp}\pre-upgrade.ps1') + '" ' + Args, '', SW_HIDE, ewWaitUntilTerminated, Code);
    if Code = 1 then
    begin
      if SuppressibleMsgBox('The database could not be backed up before the upgrade (see logs\backup.log).' + #13#10#13#10 +
        'Continue the upgrade without a backup?', mbConfirmation, MB_YESNO or MB_DEFBUTTON2, IDNO) = IDNO then
      begin
        Result := 'The upgrade was cancelled because the database backup failed. Nothing was changed.';
        Exit;
      end;
      Exec('powershell.exe', '-NoProfile -ExecutionPolicy Bypass -File "' + ExpandConstant('{tmp}\pre-upgrade.ps1') + '" ' + Args + ' -SkipBackup', '', SW_HIDE, ewWaitUntilTerminated, Code);
    end;
    if Code = 2 then
    begin
      Result := 'The old application files could not be removed. Close any program using ' + AppDir + 'app and run setup again.';
      Exit;
    end;
  end;
  Exec(ExpandConstant('{sys}\net.exe'), 'stop {#ShortName}-Apache', '', SW_HIDE, ewWaitUntilTerminated, Code);
  if FullInstallNeeded then
    Exec(ExpandConstant('{sys}\net.exe'), 'stop {#ShortName}-MySQL', '', SW_HIDE, ewWaitUntilTerminated, Code);
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  Code: Integer;
  Args, Company: String;
begin
  if CurStep <> ssPostInstall then
    Exit;

  if LicenseEnabled = 1 then
  begin
    ForceDirectories(ExpandConstant('{app}\config'));
    SaveStringToFile(ExpandConstant('{app}\config\license.key'), LicenseKey, False);
  end;

  Company := Trim(ServerPage.Values[1]);
  StringChangeEx(Company, '"', '', True);
  Args := '-NoProfile -ExecutionPolicy Bypass -File "' + ExpandConstant('{app}\installer\configure.ps1') + '"' +
    ' -InstallDir "' + ExpandConstant('{app}') + '" -DownloadDir "' + ExpandConstant('{tmp}') + '"' +
    ' -HttpPort ' + HttpPort + ' -CompanyName "' + Company + '" -AppVersion {#AppVersion}';
  // Licensing is decided by this setup.exe, not by the editable app.ini copy on the client.
  if LicenseEnabled = 1 then
    Args := Args + ' -LicenseRequired';
  if WizardIsTaskSelected('firewall') then
    Args := Args + ' -OpenFirewall';
  if FullInstall then
    WizardForm.StatusLabel.Caption := 'Setting up MySQL, PHP and Apache (this can take a few minutes)...'
  else
  begin
    Args := Args + ' -AppOnly';
    WizardForm.StatusLabel.Caption := 'Updating the application and restarting the web server...';
  end;

  WizardForm.ProgressGauge.Style := npbstMarquee;
  try
    ConfigureOk := Exec('powershell.exe', Args, '', SW_HIDE, ewWaitUntilTerminated, Code) and (Code = 0);
  finally
    WizardForm.ProgressGauge.Style := npbstNormal;
  end;
  if not ConfigureOk then
    SuppressibleMsgBox('The files were installed, but setting up the database or web server failed.' + #13#10#13#10 +
      'Details: the newest install-*.log in ' + ExpandConstant('{app}\logs') + #13#10#13#10 +
      'Fix the problem and run setup again (with /REPAIR if this version is already installed).', mbError, MB_OK, IDOK);
end;

function ConfigureSucceeded(): Boolean;
begin
  Result := ConfigureOk;
end;

function GetAppUrl(Param: String): String;
begin
  Result := GetIniString('install', 'url', 'http://localhost:' + HttpPort + '/', InstallIni);
end;
