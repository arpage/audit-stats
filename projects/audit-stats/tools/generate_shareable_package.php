<?php
/**
 * generate_shareable_package.php
 *
 * Generates an index.html listing all HTML and PDF reports in the output directory,
 * and creates a zip file of the entire output directory for easy sharing.
 *
 * Usage: php tools/generate_shareable_package.php
 *
 * Outputs:
 *   output/index.html        - Navigation page listing all reports
 *   output/audit-stats.zip   - Zip archive of all output files
 */

$out_dir = __DIR__ . '/../output';
$index_file = "$out_dir/index.html";
$zip_file = "$out_dir/audit-stats.zip";

// ─── Discover all report files ───────────────────────────────────────────────

$all_files = ['html' => [], 'pdf' => [], 'md' => []];

$iterator = new DirectoryIterator($out_dir);
foreach ($iterator as $file) {
    if ($file->isFile()) {
        $name = $file->getFilename();
        $ext = $file->getExtension();

        // Skip system files
        if ($name === '.gitkeep' || $name === '.token_log.jsonl' || $name === 'summary.json' || $name === 'index.html' || $name === 'audit-stats.zip') {
            continue;
        }

        if (isset($all_files[$ext])) {
            $all_files[$ext][] = $name;
        }
    }
}

// ─── Extract dates and build report groups ───────────────────────────────────

function extractDate($filename) {
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $filename, $matches)) {
        return $matches[1];
    }
    return '';
}

function formatDate($dateStr) {
    $ts = strtotime($dateStr);
    if ($ts === false) return $dateStr;
    return date('F j, Y', $ts);
}

// Classify a filename as 'week' (per-week report), 'analysis' (cross-week), or 'other'
function reportType($filename) {
    if (preg_match('/-week-report\.\w+$/', $filename)) return 'week';
    if (preg_match('/-report\.\w+$/', $filename))      return 'analysis';
    return 'other';
}

// Build two separate report groups
$week_reports     = [];
$analysis_reports = [];

foreach ($all_files as $ext => $files) {
    rsort($files);
    foreach ($files as $f) {
        $date = extractDate($f);
        $type = reportType($f);
        if ($type === 'week') {
            if (!isset($week_reports[$date])) $week_reports[$date] = [];
            $week_reports[$date][$ext] = $f;
        } elseif ($type === 'analysis') {
            if (!isset($analysis_reports[$date])) $analysis_reports[$date] = [];
            $analysis_reports[$date][$ext] = $f;
        }
    }
}

// Sort each group by date descending
krsort($week_reports);
krsort($analysis_reports);

// ─── Generate index.html ─────────────────────────────────────────────────────

function buildRows($reports) {
    $rows = '';
    foreach ($reports as $date => $files) {
        $formatted_date = formatDate($date);
        $html_link = isset($files['html'])
            ? "<a href=\"" . htmlspecialchars($files['html']) . "\">HTML</a>"
            : '<span style="color:#999">N/A</span>';
        $pdf_link = isset($files['pdf'])
            ? "<a href=\"" . htmlspecialchars($files['pdf']) . "\">PDF</a>"
            : '<span style="color:#999">N/A</span>';
        $md_link = isset($files['md'])
            ? "<a href=\"" . htmlspecialchars($files['md']) . "\">Markdown</a>"
            : '<span style="color:#999">N/A</span>';

        $rows .= "    <tr>\n";
        $rows .= "      <td>$formatted_date</td>\n";
        $rows .= "      <td>$date</td>\n";
        $rows .= "      <td>$html_link</td>\n";
        $rows .= "      <td>$pdf_link</td>\n";
        $rows .= "      <td>$md_link</td>\n";
        $rows .= "    </tr>\n";
    }
    return $rows;
}

$week_rows     = buildRows($week_reports);
$analysis_rows = buildRows($analysis_reports);

$generated_date   = date('Y-m-d H:i:s T');
$total_week       = count($week_reports);
$total_analysis   = count($analysis_reports);

$html_content = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cloud Foundry Audit Stats - Report Index</title>
  <style>
    :root {
      --primary: #2563eb;
      --primary-hover: #1d4ed8;
      --bg: #f8fafc;
      --card-bg: #ffffff;
      --text: #1e293b;
      --text-muted: #64748b;
      --border: #e2e8f0;
      --success: #16a34a;
    }
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
      background: var(--bg);
      color: var(--text);
      line-height: 1.6;
      padding: 2rem;
      min-height: 100vh;
    }
    .container {
      max-width: 960px;
      margin: 0 auto;
    }
    header {
      text-align: center;
      margin-bottom: 2rem;
      padding-bottom: 1.5rem;
      border-bottom: 1px solid var(--border);
    }
    h1 {
      font-size: 1.75rem;
      font-weight: 700;
      color: var(--text);
      margin-bottom: 0.5rem;
    }
    .subtitle {
      color: var(--text-muted);
      font-size: 0.95rem;
    }
    .stats-bar {
      display: flex;
      gap: 1.5rem;
      justify-content: center;
      margin-bottom: 2rem;
      flex-wrap: wrap;
    }
    .stat-item {
      background: var(--card-bg);
      padding: 0.75rem 1.25rem;
      border-radius: 8px;
      border: 1px solid var(--border);
      font-size: 0.875rem;
    }
    .stat-item strong {
      color: var(--primary);
    }
    .card {
      background: var(--card-bg);
      border-radius: 12px;
      border: 1px solid var(--border);
      overflow: hidden;
      box-shadow: 0 1px 3px rgba(0,0,0,0.05);
      margin-bottom: 2rem;
    }
    .card-header {
      padding: 1rem 1.5rem;
      border-bottom: 1px solid var(--border);
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .card-header h2 {
      font-size: 1.125rem;
      font-weight: 600;
    }
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.5rem 1rem;
      border-radius: 6px;
      text-decoration: none;
      font-size: 0.875rem;
      font-weight: 500;
      transition: all 0.2s;
    }
    .btn-primary {
      background: var(--primary);
      color: white;
    }
    .btn-primary:hover {
      background: var(--primary-hover);
    }
    table {
      width: 100%;
      border-collapse: collapse;
    }
    th, td {
      padding: 0.75rem 1rem;
      text-align: left;
      border-bottom: 1px solid var(--border);
    }
    th {
      background: var(--bg);
      font-weight: 600;
      font-size: 0.8rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--text-muted);
    }
    tr:last-child td {
      border-bottom: none;
    }
    tr:hover td {
      background: var(--bg);
    }
    a {
      color: var(--primary);
      text-decoration: none;
    }
    a:hover {
      text-decoration: underline;
    }
    footer {
      text-align: center;
      color: var(--text-muted);
      font-size: 0.8rem;
      padding-top: 1.5rem;
      border-top: 1px solid var(--border);
    }
    @media (max-width: 640px) {
      body {
        padding: 1rem;
      }
      .stats-bar {
        flex-direction: column;
        align-items: stretch;
      }
      table {
        font-size: 0.875rem;
      }
      th, td {
        padding: 0.5rem 0.75rem;
      }
    }
  </style>
</head>
<body>
  <div class="container">
    <header>
      <h1>Cloud Foundry Audit Stats</h1>
      <p class="subtitle">Weekly analysis reports for usa.gov infrastructure monitoring</p>
    </header>

    <div class="stats-bar">
      <div class="stat-item">
        <strong>$total_week</strong> per-week reports
      </div>
      <div class="stat-item">
        <strong>$total_analysis</strong> cross-week analyses
      </div>
      <div class="stat-item">
        Generated: <strong>$generated_date</strong>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h2>Per-Week Reports</h2>
        <a href="audit-stats.zip" class="btn btn-primary">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Download ZIP
        </a>
      </div>
      <table>
        <thead>
          <tr>
            <th>Week Ending</th>
            <th>Date Code</th>
            <th>HTML</th>
            <th>PDF</th>
            <th>Markdown</th>
          </tr>
        </thead>
        <tbody>
$week_rows
        </tbody>
      </table>
    </div>

    <div class="card">
      <div class="card-header">
        <h2>Cross-Week Analysis</h2>
      </div>
      <table>
        <thead>
          <tr>
            <th>Through</th>
            <th>Date Code</th>
            <th>HTML</th>
            <th>PDF</th>
            <th>Markdown</th>
          </tr>
        </thead>
        <tbody>
$analysis_rows
        </tbody>
      </table>
    </div>

    <footer>
      <p>Cloud Foundry Audit Statistics &mdash; Automated weekly analysis of deployment, SSH, proxy, and WAF activity.</p>
    </footer>
  </div>
</body>
</html>
HTML;

file_put_contents($index_file, $html_content);
echo "Generated: $index_file\n";

// ─── Create ZIP archive ──────────────────────────────────────────────────────

if (!extension_loaded('zip')) {
    fwrite(STDERR, "Error: PHP Zip extension is not loaded\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Error: Cannot create zip file: $zip_file\n");
    exit(1);
}

$files_added = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($out_dir),
    RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ($iterator as $file) {
    if (!$file->isFile()) continue;
    
    $filepath = $file->getPathname();
    $relative_path = $file->getFilename();
    
    // Skip the zip file itself and hidden/system files
    if ($relative_path === 'audit-stats.zip' || $relative_path === '.gitkeep') {
        continue;
    }
    
    $zip->addFile($filepath, $relative_path);
    $files_added++;
}

$zip->close();
echo "Generated: $zip_file ($files_added files)\n";
echo "Done.\n";
