import React from 'react';
import { Box, Card, CardContent, Typography } from '@mui/material';
import {
  Bar,
  BarChart,
  CartesianGrid,
  Legend,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';

import { SERIES } from '../../utils/constants';

const GRID = '#e6e6e3';
const AXIS = { fontSize: 12, fill: '#52514e' };

export const ChartCard = ({ title, subtitle, children, height = 280, empty }) => (
  <Card sx={{ height: '100%' }}>
    <CardContent>
      <Typography variant="subtitle1" sx={{ fontWeight: 600 }}>{title}</Typography>
      {subtitle && <Typography variant="caption" color="text.secondary">{subtitle}</Typography>}
      <Box sx={{ height, mt: 1 }}>
        {empty ? (
          <Box sx={{ height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'text.secondary' }}>
            <Typography variant="body2">No data for the selected period</Typography>
          </Box>
        ) : children}
      </Box>
    </CardContent>
  </Card>
);

/**
 * Time series: one y-axis, 2px lines, crosshair tooltip. `series` = [{ key, name }].
 */
export const TrendChart = ({ data, series, xKey = 'period', formatValue }) => (
  <ResponsiveContainer width="100%" height="100%">
    <LineChart data={data} margin={{ top: 8, right: 16, left: 0, bottom: 0 }}>
      <CartesianGrid stroke={GRID} vertical={false} />
      <XAxis dataKey={xKey} tick={AXIS} tickLine={false} axisLine={{ stroke: GRID }} minTickGap={24} />
      <YAxis tick={AXIS} tickLine={false} axisLine={false} allowDecimals={false} width={48} tickFormatter={formatValue} />
      <Tooltip formatter={formatValue ? (v) => formatValue(v) : undefined} cursor={{ stroke: '#9e9e9e', strokeDasharray: '3 3' }} />
      {series.length > 1 && <Legend wrapperStyle={{ fontSize: 12 }} />}
      {series.map((s, i) => (
        <Line key={s.key} type="monotone" dataKey={s.key} name={s.name} stroke={SERIES[i]} strokeWidth={2} dot={false} activeDot={{ r: 4 }} />
      ))}
    </LineChart>
  </ResponsiveContainer>
);

/**
 * Horizontal bars for category magnitudes ({ name, value }); single hue.
 */
export const CategoryBarChart = ({ data, color = SERIES[0], formatValue }) => (
  <ResponsiveContainer width="100%" height="100%">
    <BarChart data={data} layout="vertical" margin={{ top: 4, right: 24, left: 8, bottom: 0 }} barCategoryGap={4}>
      <CartesianGrid stroke={GRID} horizontal={false} />
      <XAxis type="number" tick={AXIS} tickLine={false} axisLine={false} allowDecimals={false} tickFormatter={formatValue} />
      <YAxis type="category" dataKey="name" tick={AXIS} tickLine={false} axisLine={false} width={130} />
      <Tooltip formatter={formatValue ? (v) => formatValue(v) : undefined} cursor={{ fill: '#f0f0ee' }} />
      <Bar dataKey="value" name="Count" fill={color} radius={[0, 4, 4, 0]} maxBarSize={22} />
    </BarChart>
  </ResponsiveContainer>
);

/**
 * Grouped vertical bars over time. `series` = [{ key, name }].
 */
export const GroupedBarChart = ({ data, series, xKey = 'period', formatValue }) => (
  <ResponsiveContainer width="100%" height="100%">
    <BarChart data={data} margin={{ top: 8, right: 16, left: 0, bottom: 0 }} barGap={2}>
      <CartesianGrid stroke={GRID} vertical={false} />
      <XAxis dataKey={xKey} tick={AXIS} tickLine={false} axisLine={{ stroke: GRID }} minTickGap={24} />
      <YAxis tick={AXIS} tickLine={false} axisLine={false} allowDecimals={false} width={48} tickFormatter={formatValue} />
      <Tooltip formatter={formatValue ? (v) => formatValue(v) : undefined} cursor={{ fill: '#f0f0ee' }} />
      <Legend wrapperStyle={{ fontSize: 12 }} />
      {series.map((s, i) => (
        <Bar key={s.key} dataKey={s.key} name={s.name} fill={SERIES[i]} radius={[4, 4, 0, 0]} maxBarSize={18} />
      ))}
    </BarChart>
  </ResponsiveContainer>
);
