import React, { useState, useEffect } from 'react';
import {
	Box,
	Paper,
	Typography,
	Grid,
	Card,
	CardContent,
	Table,
	TableBody,
	TableCell,
	TableContainer,
	TableHead,
	TableRow,
	Chip,
	TextField,
	MenuItem,
	IconButton,
	CircularProgress,
	Alert,
	FormControl,
	InputLabel,
	Select,
} from '@mui/material';
import {
	TrendingUp,
	TrendingDown,
	AttachMoney,
	ShoppingCart,
	People,
	Inventory,
	Refresh,
	DateRange,
	BarChart,
	PieChart,
	Timeline,
} from '@mui/icons-material';
import { DatePicker } from '@mui/x-date-pickers/DatePicker';
import { LocalizationProvider } from '@mui/x-date-pickers/LocalizationProvider';
import { AdapterDateFns } from '@mui/x-date-pickers/AdapterDateFns';
import { BarChart as ReBarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer, PieChart as RePieChart, Pie, Cell, LineChart, Line } from 'recharts';
import { Link as RouterLink } from 'react-router-dom';
import {
	orderService,
	inventoryService,
} from '../services/api';
import { format, subDays, startOfMonth, endOfMonth } from 'date-fns';

const Reports = () => {
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState('');
	const [dateRange, setDateRange] = useState({
		startDate: startOfMonth(new Date()),
		endDate: endOfMonth(new Date()),
	});
	const [reportType, setReportType] = useState('sales');

	// State for reports data
	const [salesStats, setSalesStats] = useState(null);
	const [topProducts, setTopProducts] = useState([]);
	const [topCustomers, setTopCustomers] = useState([]);
	const [inventorySummary, setInventorySummary] = useState([]);
	const [salesData, setSalesData] = useState([]);
	const [salesByCategory, setSalesByCategory] = useState([]);
	const [inventoryByCategory, setInventoryByCategory] = useState([]);

	// Color palette for charts
	const COLORS = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];

	// Load reports data
	const loadReportsData = async () => {
		setLoading(true);
		setError('');

		try {
			// Load sales statistics
			const statsParams = {
				start_date: format(dateRange.startDate, 'yyyy-MM-dd'),
				end_date: format(dateRange.endDate, 'yyyy-MM-dd'),
			};

			const [salesResponse, inventoryResponse] = await Promise.all([
				orderService.getSalesStats(statsParams),
				inventoryService.getSummary(),
			]);

			// Process sales data
			setSalesStats(salesResponse.data?.statistics || {});
			setTopProducts(salesResponse.data?.top_products || []);
			setSalesByCategory((salesResponse.data?.sales_by_category || []).map((row) => ({ name: row.name, value: Number(row.value) })));

			// Top customers by actual spend in the period
			setTopCustomers((salesResponse.data?.top_customers || []).map((customer) => ({
				...customer,
				total_spent: Number(customer.total_spent),
			})));

			// Process inventory data
			setInventorySummary(inventoryResponse.data?.low_stock || []);
			const byCategory = {};
			(inventoryResponse.data?.summary || []).forEach((row) => {
				const category = row.product?.category || 'Uncategorized';
				byCategory[category] = (byCategory[category] || 0) + Number(row.total_quantity || 0);
			});
			setInventoryByCategory(Object.entries(byCategory).map(([name, stock]) => ({ name, stock })));

			// Daily sales series from recorded orders
			setSalesData((salesResponse.data?.daily_sales || []).map((day) => ({
				date: format(new Date(day.date), 'MMM dd'),
				sales: Number(day.sales),
				orders: Number(day.orders),
			})));

		} catch (err) {
			console.error('Error loading reports:', err);
			setError('Failed to load reports data. Please try again.');
		} finally {
			setLoading(false);
		}
	};

	// Load data on component mount and when date range changes
	useEffect(() => {
		loadReportsData();
	}, [dateRange]);

	// Format currency
	const formatCurrency = (amount) => {
		return new Intl.NumberFormat('en-PH', {
			style: 'currency',
			currency: 'PHP',
		}).format(amount);
	};

	// Stat Card Component
	const StatCard = ({ title, value, icon, trend, color }) => (
		<Card sx={{ height: '100%' }}>
			<CardContent>
				<Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
					<Box>
						<Typography color="textSecondary" variant="body2" gutterBottom>
							{title}
						</Typography>
						<Typography variant="h4" sx={{ fontWeight: 'bold', color }}>
							{title.includes('Revenue') || title.includes('Sales') ? formatCurrency(value) : value}
						</Typography>
						{trend && (
							<Box sx={{ display: 'flex', alignItems: 'center', mt: 1 }}>
								{trend > 0 ? (
									<TrendingUp sx={{ fontSize: 16, color: '#4caf50', mr: 0.5 }} />
								) : (
									<TrendingDown sx={{ fontSize: 16, color: '#f44336', mr: 0.5 }} />
								)}
								<Typography variant="body2" color={trend > 0 ? '#4caf50' : '#f44336'}>
									{Math.abs(trend)}% {trend > 0 ? 'increase' : 'decrease'}
								</Typography>
							</Box>
						)}
					</Box>
					<Box sx={{ color, opacity: 0.8 }}>
						{icon}
					</Box>
				</Box>
			</CardContent>
		</Card>
	);

	if (loading) {
		return (
			<Box sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', minHeight: '400px' }}>
				<CircularProgress />
			</Box>
		);
	}

	return (
		<LocalizationProvider dateAdapter={AdapterDateFns}>
			<Box>
				{/* Header */}
				<Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 4 }}>
					<Typography variant="h4" sx={{ color: '#1a237e', fontWeight: 'bold' }}>
						Reports & Analytics
					</Typography>
					<Box sx={{ display: 'flex', gap: 2, alignItems: 'center' }}>
						<FormControl size="small" sx={{ minWidth: 150 }}>
							<InputLabel>Report Type</InputLabel>
							<Select
								value={reportType}
								label="Report Type"
								onChange={(e) => setReportType(e.target.value)}
							>
								<MenuItem value="sales">Sales Report</MenuItem>
								<MenuItem value="inventory">Inventory Report</MenuItem>
								<MenuItem value="customers">Customers Report</MenuItem>
							</Select>
						</FormControl>
						<IconButton onClick={loadReportsData} color="primary">
							<Refresh />
						</IconButton>
					</Box>
				</Box>

				{error && (
					<Alert severity="error" sx={{ mb: 3 }}>
						{error}
					</Alert>
				)}

				{/* Date Range Selector */}
				<Paper sx={{ p: 3, mb: 4 }}>
					<Typography variant="h6" gutterBottom sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
						<DateRange /> Date Range
					</Typography>
					<Box sx={{ display: 'flex', gap: 3, flexWrap: 'wrap' }}>
						<DatePicker
							label="Start Date"
							value={dateRange.startDate}
							onChange={(newValue) => setDateRange({ ...dateRange, startDate: newValue })}
							renderInput={(params) => <TextField {...params} size="small" />}
						/>
						<DatePicker
							label="End Date"
							value={dateRange.endDate}
							onChange={(newValue) => setDateRange({ ...dateRange, endDate: newValue })}
							renderInput={(params) => <TextField {...params} size="small" />}
						/>
						<Box sx={{ display: 'flex', gap: 1, flexGrow: 1, justifyContent: 'flex-end' }}>
							{['Today', 'Week', 'Month', 'Year'].map((period) => (
								<Chip
									key={period}
									label={period}
									onClick={() => {
										const now = new Date();
										if (period === 'Today') {
											setDateRange({ startDate: now, endDate: now });
										} else if (period === 'Week') {
											setDateRange({ startDate: subDays(now, 7), endDate: now });
										} else if (period === 'Month') {
											setDateRange({ startDate: startOfMonth(now), endDate: endOfMonth(now) });
										} else if (period === 'Year') {
											setDateRange({ startDate: new Date(now.getFullYear(), 0, 1), endDate: now });
										}
									}}
									variant="outlined"
									sx={{ cursor: 'pointer' }}
								/>
							))}
						</Box>
					</Box>
				</Paper>

				{/* Sales Summary Cards */}
				{reportType === 'sales' && (
					<>
						<Grid container spacing={3} sx={{ mb: 4 }}>
							<Grid item xs={12} sm={6} md={3}>
								<StatCard
									title="Total Revenue"
									value={salesStats.total_revenue || 0}
									icon={<AttachMoney sx={{ fontSize: 40 }} />}
									trend={12.5}
									color="#4caf50"
								/>
							</Grid>
							<Grid item xs={12} sm={6} md={3}>
								<StatCard
									title="Total Orders"
									value={salesStats.total_orders || 0}
									icon={<ShoppingCart sx={{ fontSize: 40 }} />}
									trend={8.2}
									color="#2196f3"
								/>
							</Grid>
							<Grid item xs={12} sm={6} md={3}>
								<StatCard
									title="Avg Order Value"
									value={salesStats.avg_order_value || 0}
									icon={<TrendingUp sx={{ fontSize: 40 }} />}
									trend={4.7}
									color="#ff9800"
								/>
							</Grid>
							<Grid item xs={12} sm={6} md={3}>
								<StatCard
									title="Total Customers"
									value={topCustomers.length || 0}
									icon={<People sx={{ fontSize: 40 }} />}
									trend={15.3}
									color="#9c27b0"
								/>
							</Grid>
						</Grid>

						{/* Charts */}
						<Grid container spacing={3} sx={{ mb: 4 }}>
							<Grid item xs={12} md={8}>
								<Paper sx={{ p: 3, height: 400 }}>
									<Typography variant="h6" gutterBottom sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
										<Timeline /> Daily Sales (Selected Period)
									</Typography>
									{salesData.length === 0 ? (
										<Typography color="textSecondary" align="center" sx={{ py: 8 }}>No sales in the selected period</Typography>
									) : (
										<ResponsiveContainer width="100%" height="90%">
											<LineChart data={salesData}>
												<CartesianGrid stroke="#e6e6e3" vertical={false} />
												<XAxis dataKey="date" />
												<YAxis />
												<Tooltip formatter={(value) => [formatCurrency(value), 'Sales']} />
												<Line type="monotone" dataKey="sales" name="Sales" stroke="#2a78d6" strokeWidth={2} dot={false} />
											</LineChart>
										</ResponsiveContainer>
									)}
								</Paper>
							</Grid>
							<Grid item xs={12} md={4}>
								<Paper sx={{ p: 3, height: 400 }}>
									<Typography variant="h6" gutterBottom sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
										<PieChart /> Sales by Category
									</Typography>
									{salesByCategory.length === 0 ? (
										<Typography color="textSecondary" align="center" sx={{ py: 8 }}>No sales in the selected period</Typography>
									) : (
										<ResponsiveContainer width="100%" height="90%">
											<RePieChart>
												<Pie
													data={salesByCategory}
													cx="50%"
													cy="50%"
													labelLine={false}
													outerRadius={80}
													dataKey="value"
													stroke="#ffffff"
													strokeWidth={2}
												>
													{salesByCategory.map((entry, index) => (
														<Cell key={entry.name} fill={COLORS[index % COLORS.length]} />
													))}
												</Pie>
												<Tooltip formatter={(value) => [formatCurrency(value), 'Sales']} />
												<Legend />
											</RePieChart>
										</ResponsiveContainer>
									)}
								</Paper>
							</Grid>
						</Grid>

						{/* Top Products Table */}
						<Paper sx={{ p: 3, mb: 4 }}>
							<Typography variant="h6" gutterBottom sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
								<BarChart /> Top Selling Products
							</Typography>
							<TableContainer>
								<Table>
									<TableHead>
										<TableRow>
											<TableCell>Product</TableCell>
											<TableCell align="right">Quantity Sold</TableCell>
											<TableCell align="right">Total Revenue</TableCell>
											<TableCell align="right">Category</TableCell>
											<TableCell>Performance</TableCell>
										</TableRow>
									</TableHead>
									<TableBody>
										{topProducts.length > 0 ? (
											topProducts.map((product) => (
												<TableRow key={product.product_name}>
													<TableCell>{product.product_name}</TableCell>
													<TableCell align="right">{product.total_quantity || 0}</TableCell>
													<TableCell align="right">{formatCurrency(product.total_revenue || 0)}</TableCell>
													<TableCell align="right">
														<Chip label={product.category || 'N/A'} size="small" />
													</TableCell>
													<TableCell>
														<Box sx={{ width: '100%', bgcolor: '#e0e0e0', borderRadius: 1, height: 8 }}>
															<Box
																sx={{
																	width: `${Math.min((product.total_quantity || 0) * 5, 100)}%`,
																	bgcolor: '#4caf50',
																	height: '100%',
																	borderRadius: 1,
																}}
															/>
														</Box>
													</TableCell>
												</TableRow>
											))
										) : (
											<TableRow>
												<TableCell colSpan={5} align="center">
													<Typography color="textSecondary" sx={{ py: 2 }}>
														No sales data available
													</Typography>
												</TableCell>
											</TableRow>
										)}
									</TableBody>
								</Table>
							</TableContainer>
						</Paper>
					</>
				)}

				{/* Inventory Report */}
				{reportType === 'inventory' && (
					<>
						<Paper sx={{ p: 3, mb: 4 }}>
							<Typography variant="h6" gutterBottom sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
								<Inventory /> Low Stock Alert
							</Typography>
							<TableContainer>
								<Table>
									<TableHead>
										<TableRow>
											<TableCell>Product</TableCell>
											<TableCell>Warehouse</TableCell>
											<TableCell align="right">Current Stock</TableCell>
											<TableCell align="right">Reorder Level</TableCell>
											<TableCell>Status</TableCell>
										</TableRow>
									</TableHead>
									<TableBody>
										{inventorySummary.length > 0 ? (
											inventorySummary.map((item) => (
												<TableRow key={item.id}>
													<TableCell>{item.product?.product_name || 'N/A'}</TableCell>
													<TableCell>{item.warehouse?.warehouse_name || 'N/A'}</TableCell>
													<TableCell align="right">
														<Chip
															label={item.quantity}
															color={item.quantity <= 5 ? 'error' : item.quantity <= 10 ? 'warning' : 'default'}
															size="small"
														/>
													</TableCell>
													<TableCell align="right">10</TableCell>
													<TableCell>
														{item.quantity <= 5 ? (
															<Chip label="Critical" color="error" size="small" />
														) : item.quantity <= 10 ? (
															<Chip label="Low" color="warning" size="small" />
														) : (
															<Chip label="Adequate" color="success" size="small" />
														)}
													</TableCell>
												</TableRow>
											))
										) : (
											<TableRow>
												<TableCell colSpan={5} align="center">
													<Typography color="textSecondary" sx={{ py: 2 }}>
														All inventory levels are adequate
													</Typography>
												</TableCell>
											</TableRow>
										)}
									</TableBody>
								</Table>
							</TableContainer>
						</Paper>

						{/* Inventory Chart */}
						<Paper sx={{ p: 3 }}>
							<Typography variant="h6" gutterBottom sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
								<BarChart /> Inventory Distribution
							</Typography>
							<Box sx={{ height: 400 }}>
								<ResponsiveContainer width="100%" height="100%">
									<ReBarChart data={inventoryByCategory}>
										<CartesianGrid strokeDasharray="3 3" />
										<XAxis dataKey="name" />
										<YAxis />
										<Tooltip formatter={(value) => [value, 'Units']} />
										<Legend />
										<Bar dataKey="stock" fill="#2a78d6" name="Current Stock" radius={[4, 4, 0, 0]} />
									</ReBarChart>
								</ResponsiveContainer>
							</Box>
						</Paper>
					</>
				)}

				{/* Customers Report */}
				{reportType === 'customers' && (
					<>
						<Paper sx={{ p: 3, mb: 4 }}>
							<Typography variant="h6" gutterBottom sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
								<People /> Top Customers
							</Typography>
							<TableContainer>
								<Table>
									<TableHead>
										<TableRow>
											<TableCell>Customer</TableCell>
											<TableCell>Customer ID</TableCell>
											<TableCell>Email</TableCell>
											<TableCell align="right">Total Orders</TableCell>
											<TableCell align="right">Total Spent</TableCell>
										</TableRow>
									</TableHead>
									<TableBody>
										{topCustomers.length === 0 && (
											<TableRow>
												<TableCell colSpan={5} align="center">
													<Typography color="textSecondary" sx={{ py: 2 }}>No customer purchases in the selected period</Typography>
												</TableCell>
											</TableRow>
										)}
										{topCustomers.map((customer) => (
											<TableRow key={customer.id}>
												<TableCell>{customer.full_name}</TableCell>
												<TableCell>{customer.customer_code}</TableCell>
												<TableCell>{customer.email || '-'}</TableCell>
												<TableCell align="right">{customer.total_orders}</TableCell>
												<TableCell align="right">{formatCurrency(customer.total_spent)}</TableCell>
											</TableRow>
										))}
									</TableBody>
								</Table>
							</TableContainer>
						</Paper>

						{/* Customer analytics live in the Customer Management module */}
						<Alert severity="info">
							Registration trends, new vs returning customers, segmentation and inactive-customer reports are available in{' '}
							<RouterLink to="/customer-reports">Customer Reports</RouterLink> and the{' '}
							<RouterLink to="/customer-management">Customer Dashboard</RouterLink>.
						</Alert>
					</>
				)}
			</Box>
		</LocalizationProvider>
	);
};

export default Reports;
