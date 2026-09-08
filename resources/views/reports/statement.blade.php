<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>FinBalance — Финансовый отчёт</title>
  <style>
    body, h1, h2, h3, p, td, th, div, span, strong { font-family: 'DejaVu Sans', sans-serif; }
    body { font-size: 12px; color: #1a1a1a; margin: 20px; line-height: 1.5; }
    h1 { color: #15B648; font-size: 22px; margin-bottom: 4px; }
    h2 { color: #15B648; font-size: 16px; margin-top: 20px; margin-bottom: 8px; border-bottom: 2px solid #15B648; padding-bottom: 4px; }
    .meta { color: #555; font-size: 11px; margin-bottom: 16px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; font-size: 11px; }
    th { background-color: #15B648; color: #fff; }
    .right { text-align: right; }
    .green { color: #15B648; font-weight: bold; }
    .red { color: #d32f2f; font-weight: bold; }
    .section { margin-bottom: 16px; }
    .insight-box { background: #f5f5f5; border-left: 3px solid #15B648; padding: 8px 12px; margin-bottom: 8px; }
    .insight-box strong { color: #15B648; }
    .footer { margin-top: 30px; padding-top: 8px; border-top: 1px solid #ccc; font-size: 10px; color: #888; text-align: center; }
  </style>
</head>
<body>

<h1>FinBalance — Финансовый отчёт</h1>
<p class="meta">
  Период выписки: {{ $statement->period_from ?? '—' }} — {{ $statement->period_to ?? '—' }}<br>
  Файл: {{ $statement->file_name }}<br>
  Сформирован: {{ now()->format('d.m.Y H:i') }}
</p>

<h2>Итоги</h2>
<table>
  <tr>
    <th>Показатель</th>
    <th class="right">Значение</th>
  </tr>
  <tr>
    <td>Доходы</td>
    <td class="right green">{{ number_format($totals['total_income'], 2, ',', ' ') }} ₽</td>
  </tr>
  <tr>
    <td>Расходы</td>
    <td class="right red">{{ number_format($totals['total_expenses'], 2, ',', ' ') }} ₽</td>
  </tr>
  <tr>
    <td>Баланс</td>
    <td class="right {{ $totals['balance'] >= 0 ? 'green' : 'red' }}">{{ number_format($totals['balance'], 2, ',', ' ') }} ₽</td>
  </tr>
  <tr>
    <td>Количество транзакций</td>
    <td class="right">{{ $totals['transactions_count'] }}</td>
  </tr>
  <tr>
    <td>Средний расход</td>
    <td class="right">{{ number_format($totals['average_expense'], 2, ',', ' ') }} ₽</td>
  </tr>
</table>

@if(count($byCategory) > 0)
<h2>Расходы по категориям</h2>
<table>
  <tr>
    <th>Категория</th>
    <th class="right">Сумма</th>
    <th class="right">% от оборота</th>
  </tr>
  @foreach($byCategory as $cat)
  <tr>
    <td>{{ $cat['category'] }}</td>
    <td class="right">{{ number_format($cat['amount'], 2, ',', ' ') }} ₽</td>
    <td class="right">{{ number_format($cat['percentage'], 1, ',', ' ') }}%</td>
  </tr>
  @endforeach
</table>
@endif

@if(count($insights) > 0)
<h2>AI-инсайты</h2>
@foreach($insights as $insight)
<div class="insight-box">
  <strong>{{ $insight->title }}</strong><br>
  @if($insight->description)
    {{ $insight->description }}<br>
  @endif
  @if($insight->type === 'recommendation' && $insight->data)
    @if(!empty($insight->data['recommendation']))
      Рекомендация: {{ $insight->data['recommendation'] }}<br>
    @endif
    @if(!empty($insight->data['monthly_saving']))
      Экономия в месяц: {{ number_format((float)$insight->data['monthly_saving'], 0, ',', ' ') }} ₽<br>
    @endif
    @if(!empty($insight->data['annual_saving']))
      Экономия в год: {{ number_format((float)$insight->data['annual_saving'], 0, ',', ' ') }} ₽
    @endif
  @endif
</div>
@endforeach
@endif

<div class="footer">
  FinBalance &mdash; Финансовый анализатор выписок | finbalance.ru
</div>

</body>
</html>
