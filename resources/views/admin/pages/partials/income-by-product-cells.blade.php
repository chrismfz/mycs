{{-- One row's figures on Income by product (a category or a product): its parent's $money, $percent, $tone, $tenth, $perService. --}}
<td class="py-2 pr-4 whitespace-nowrap">{{ $money($row['received']) }}</td>
<td class="py-2 pr-4 whitespace-nowrap font-normal">{{ $row['last_year'] ? $money($row['last_year']) : '—' }}</td>
<td class="py-2 pr-4 whitespace-nowrap font-normal {{ $percent($row['received'], $row['last_year']) === null ? '' : $tone($row['received'], $row['last_year']) }}">{{ $percent($row['received'], $row['last_year']) ?? '—' }}</td>
<td class="py-2 pr-4 whitespace-nowrap font-normal">{{ $tenth($row['received']) }}</td>
<td class="py-2 pr-4 whitespace-nowrap font-normal">{{ $row['services'] === null ? '—' : $row['services'] }}</td>
<td class="py-2 pr-4 whitespace-nowrap font-normal">{{ $perService($row) }}</td>
<td class="py-2 pr-4 whitespace-nowrap font-normal">{{ $row['annual'] ? $money($row['annual']) : '—' }}</td>
<td class="py-2 whitespace-nowrap font-normal text-gray-500">{{ $row['new'] === null ? '—' : '+' . $row['new'] . ' / −' . $row['ended'] }}</td>
