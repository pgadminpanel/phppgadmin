(function () {
	"use strict";

	// Configuration
	const MAX_DATA_POINTS = 100;
	const COLORS = {
		blue: "#3B82F6",
		green: "#10B981",
		orange: "#F97316",
		purple: "#8B5CF6",
		red: "#EF4444",
		yellow: "#F59E0B",
	};

	// State
	let charts = {};
	let dataHistory = {
		labels: [],
		sessions: { total: [], active: [], idle: [] },
		transactions: { commits: [], rollbacks: [], total: [] },
		tuplesIn: { inserts: [], updates: [], deletes: [] },
		tuplesOut: { fetched: [], returned: [] },
		blocks: { reads: [], hits: [] },
	};
	let previousStats = null;
	let isPaused = false;
	let pollInterval = null;

	/**
	 * Initialize data arrays with zeros for constant timeline
	 */
	function initializeDataArrays() {
		// Pre-fill with 100 zero values and placeholder labels
		for (let i = 0; i < MAX_DATA_POINTS; i++) {
			dataHistory.labels.push("");
			dataHistory.sessions.total.push(0);
			dataHistory.sessions.active.push(0);
			dataHistory.sessions.idle.push(0);
			dataHistory.transactions.commits.push(0);
			dataHistory.transactions.rollbacks.push(0);
			dataHistory.transactions.total.push(0);
			dataHistory.tuplesIn.inserts.push(0);
			dataHistory.tuplesIn.updates.push(0);
			dataHistory.tuplesIn.deletes.push(0);
			dataHistory.tuplesOut.fetched.push(0);
			dataHistory.tuplesOut.returned.push(0);
			dataHistory.blocks.reads.push(0);
			dataHistory.blocks.hits.push(0);
		}
	}

	/**
	 * Initialize all charts
	 */
	function initializeCharts() {
		const commonOptions = {
			responsive: true,
			maintainAspectRatio: true,
			plugins: {
				legend: {
					position: "top",
				},
			},
			scales: {
				x: {
					display: true,
					ticks: {
						maxTicksLimit: 10,
						autoSkip: true,
						maxRotation: 0,
						minRotation: 0,
					},
				},
				y: {
					beginAtZero: true,
					suggestedMin: 0,
				},
			},
			animation: {
				duration: 0,
			},
		};

		// Sessions Chart
		charts.sessions = new Chart(document.getElementById("sessionsChart"), {
			type: "line",
			data: {
				labels: dataHistory.labels,
				datasets: [
					{
						label: Database.lang.active,
						data: dataHistory.sessions.active,
						borderColor: COLORS.orange,
						backgroundColor: COLORS.orange,
						tension: 0.4,
						borderWidth: 1,
						radius: 2,
					},
					{
						label: Database.lang.idle,
						data: dataHistory.sessions.idle,
						borderColor: COLORS.green,
						backgroundColor: COLORS.green,
						tension: 0.4,
						borderWidth: 1,
						radius: 2,
					},
					{
						label: Database.lang.total,
						data: dataHistory.sessions.total,
						borderColor: COLORS.blue,
						backgroundColor: COLORS.blue,
						tension: 0.4,
						borderWidth: 1,
						radius: 2,
					},
				],
			},
			options: commonOptions,
		});

		// Transactions Chart
		charts.transactions = new Chart(
			document.getElementById("transactionsChart"),
			{
				type: "line",
				data: {
					labels: dataHistory.labels,
					datasets: [
						{
							label: Database.lang.transactions,
							data: dataHistory.transactions.total,
							borderColor: COLORS.blue,
							backgroundColor: COLORS.blue,
							tension: 0.4,
							borderWidth: 1,
							radius: 2,
						},
						{
							label: Database.lang.commits,
							data: dataHistory.transactions.commits,
							borderColor: COLORS.green,
							backgroundColor: COLORS.green,
							tension: 0.4,
							borderWidth: 1,
							radius: 2,
						},
						{
							label: Database.lang.rollbacks,
							data: dataHistory.transactions.rollbacks,
							borderColor: COLORS.red,
							backgroundColor: COLORS.red,
							tension: 0.4,
							borderWidth: 1,
							radius: 2,
						},
					],
				},
				options: commonOptions,
			},
		);

		// Tuples In Chart
		charts.tuplesIn = new Chart(document.getElementById("tuplesInChart"), {
			type: "line",
			data: {
				labels: dataHistory.labels,
				datasets: [
					{
						label: Database.lang.inserts,
						data: dataHistory.tuplesIn.inserts,
						borderColor: COLORS.green,
						backgroundColor: COLORS.green,
						tension: 0.4,
						borderWidth: 1,
						radius: 2,
					},
					{
						label: Database.lang.updates,
						data: dataHistory.tuplesIn.updates,
						borderColor: COLORS.blue,
						backgroundColor: COLORS.blue,
						tension: 0.4,
						borderWidth: 1,
						radius: 2,
					},
					{
						label: Database.lang.deletes,
						data: dataHistory.tuplesIn.deletes,
						borderColor: COLORS.red,
						backgroundColor: COLORS.red,
						tension: 0.4,
						borderWidth: 1,
						radius: 2,
					},
				],
			},
			options: commonOptions,
		});

		// Tuples Out Chart
		charts.tuplesOut = new Chart(
			document.getElementById("tuplesOutChart"),
			{
				type: "line",
				data: {
					labels: dataHistory.labels,
					datasets: [
						{
							label: Database.lang.fetched,
							data: dataHistory.tuplesOut.fetched,
							borderColor: COLORS.blue,
							backgroundColor: COLORS.blue,
							tension: 0.4,
							borderWidth: 1,
							radius: 2,
						},
						{
							label: Database.lang.returned,
							data: dataHistory.tuplesOut.returned,
							borderColor: COLORS.green,
							backgroundColor: COLORS.green,
							tension: 0.4,
							borderWidth: 1,
							radius: 2,
						},
					],
				},
				options: commonOptions,
			},
		);

		// Block I/O Chart
		charts.blocks = new Chart(document.getElementById("blockIOChart"), {
			type: "line",
			data: {
				labels: dataHistory.labels,
				datasets: [
					{
						label: Database.lang.reads,
						data: dataHistory.blocks.reads,
						borderColor: COLORS.orange,
						backgroundColor: COLORS.orange,
						tension: 0.4,
						borderWidth: 1,
						radius: 2,
					},
					{
						label: Database.lang.hits,
						data: dataHistory.blocks.hits,
						borderColor: COLORS.green,
						backgroundColor: COLORS.green,
						tension: 0.4,
						borderWidth: 1,
						radius: 2,
					},
				],
			},
			options: commonOptions,
		});
	}

	/**
	 * Add data point to circular buffer
	 */
	function addDataPoint(array, value) {
		array.push(value);
		if (array.length > MAX_DATA_POINTS) {
			array.shift();
		}
	}

	/**
	 * Calculate per-second rate from cumulative counters
	 */
	function calculateRate(current, previous, timeDelta) {
		if (!previous || timeDelta <= 0) return 0;
		const delta = current - previous;
		return Math.max(0, delta / timeDelta);
	}

	/**
	 * Fetch and update statistics
	 */
	function fetchStatistics() {
		fetch(
			"statistics.php?action=json_stats&server=" +
				Database.server +
				"&database=" +
				Database.dbname,
		)
			.then((response) => {
				if (!response.ok) {
					throw new Error("Network response was not ok");
				}
				return response.json();
			})
			.then((stats) => {
				updateCharts(stats);
			})
			.catch((error) => {
				console.error("Error fetching statistics:", error);
			});
	}

	/**
	 * Update all charts with new data
	 */
	function updateCharts(stats) {
		const now = new Date();
		const timeLabel = now.toLocaleTimeString();

		// Calculate time delta in seconds
		let timeDelta = 0;
		if (previousStats) {
			timeDelta = stats.timestamp - previousStats.timestamp;
		}

		// Add label
		addDataPoint(dataHistory.labels, timeLabel);

		// Sessions (absolute values)
		addDataPoint(dataHistory.sessions.total, stats.sessions.total);
		addDataPoint(dataHistory.sessions.active, stats.sessions.active);
		addDataPoint(dataHistory.sessions.idle, stats.sessions.idle);

		// Transactions (per-second rates)
		if (previousStats) {
			const commitsPerSec = calculateRate(
				stats.transactions.commits,
				previousStats.transactions.commits,
				timeDelta,
			);
			const rollbacksPerSec = calculateRate(
				stats.transactions.rollbacks,
				previousStats.transactions.rollbacks,
				timeDelta,
			);
			const totalPerSec = commitsPerSec + rollbacksPerSec;

			addDataPoint(dataHistory.transactions.total, totalPerSec);
			addDataPoint(dataHistory.transactions.commits, commitsPerSec);
			addDataPoint(dataHistory.transactions.rollbacks, rollbacksPerSec);
		} else {
			// First data point
			addDataPoint(dataHistory.transactions.total, 0);
			addDataPoint(dataHistory.transactions.commits, 0);
			addDataPoint(dataHistory.transactions.rollbacks, 0);
		}

		// Tuples In (per-second rates)
		if (previousStats) {
			const insertsPerSec = calculateRate(
				stats.tuples_in.inserts,
				previousStats.tuples_in.inserts,
				timeDelta,
			);
			const updatesPerSec = calculateRate(
				stats.tuples_in.updates,
				previousStats.tuples_in.updates,
				timeDelta,
			);
			const deletesPerSec = calculateRate(
				stats.tuples_in.deletes,
				previousStats.tuples_in.deletes,
				timeDelta,
			);

			addDataPoint(dataHistory.tuplesIn.inserts, insertsPerSec);
			addDataPoint(dataHistory.tuplesIn.updates, updatesPerSec);
			addDataPoint(dataHistory.tuplesIn.deletes, deletesPerSec);
		} else {
			addDataPoint(dataHistory.tuplesIn.inserts, 0);
			addDataPoint(dataHistory.tuplesIn.updates, 0);
			addDataPoint(dataHistory.tuplesIn.deletes, 0);
		}

		// Tuples Out (per-second rates)
		if (previousStats) {
			const fetchedPerSec = calculateRate(
				stats.tuples_out.fetched,
				previousStats.tuples_out.fetched,
				timeDelta,
			);
			const returnedPerSec = calculateRate(
				stats.tuples_out.returned,
				previousStats.tuples_out.returned,
				timeDelta,
			);

			addDataPoint(dataHistory.tuplesOut.fetched, fetchedPerSec);
			addDataPoint(dataHistory.tuplesOut.returned, returnedPerSec);
		} else {
			addDataPoint(dataHistory.tuplesOut.fetched, 0);
			addDataPoint(dataHistory.tuplesOut.returned, 0);
		}

		// Block I/O (per-second rates)
		if (previousStats) {
			const readsPerSec = calculateRate(
				stats.blocks.reads,
				previousStats.blocks.reads,
				timeDelta,
			);
			const hitsPerSec = calculateRate(
				stats.blocks.hits,
				previousStats.blocks.hits,
				timeDelta,
			);

			addDataPoint(dataHistory.blocks.reads, readsPerSec);
			addDataPoint(dataHistory.blocks.hits, hitsPerSec);
		} else {
			addDataPoint(dataHistory.blocks.reads, 0);
			addDataPoint(dataHistory.blocks.hits, 0);
		}

		// Update Chart.js data
		updateChartData(charts.sessions, dataHistory.labels, [
			dataHistory.sessions.active,
			dataHistory.sessions.idle,
			dataHistory.sessions.total,
		]);

		updateChartData(charts.transactions, dataHistory.labels, [
			dataHistory.transactions.total,
			dataHistory.transactions.commits,
			dataHistory.transactions.rollbacks,
		]);

		updateChartData(charts.tuplesIn, dataHistory.labels, [
			dataHistory.tuplesIn.inserts,
			dataHistory.tuplesIn.updates,
			dataHistory.tuplesIn.deletes,
		]);

		updateChartData(charts.tuplesOut, dataHistory.labels, [
			dataHistory.tuplesOut.fetched,
			dataHistory.tuplesOut.returned,
		]);

		updateChartData(charts.blocks, dataHistory.labels, [
			dataHistory.blocks.reads,
			dataHistory.blocks.hits,
		]);

		// Store current stats for next iteration
		previousStats = stats;
	}

	/**
	 * Update chart with new data
	 */
	function updateChartData(chart, labels, datasets) {
		chart.data.labels = labels;
		datasets.forEach((data, index) => {
			chart.data.datasets[index].data = data;
		});
		chart.update("none"); // Update without animation
	}

	/**
	 * Toggle pause/resume
	 */
	function togglePauseResume() {
		isPaused = !isPaused;
		const btn = document.getElementById("pauseResumeBtn");

		if (isPaused) {
			clearInterval(pollInterval);
			btn.classList.remove("active");
			btn.innerHTML =
				'<span class="resume-icon">▶</span> ' +
				Database.lang.resumeRefresh;
		} else {
			startPolling();
			btn.classList.add("active");
			btn.innerHTML =
				'<span class="pause-icon">⏸</span> ' +
				Database.lang.pauseRefresh;
		}
	}

	/**
	 * Start polling for statistics
	 */
	function startPolling() {
		// Fetch immediately
		fetchStatistics();

		// Then poll at configured interval
		if (Database.ajax_time_refresh > 0) {
			pollInterval = setInterval(
				fetchStatistics,
				Database.ajax_time_refresh,
			);
		}
	}

	/**
	 * Initialize on page load
	 */
	document.addEventListener(
		"frameLoaded",
		function () {
			// Initialize data arrays with zeros
			initializeDataArrays();

			// Initialize charts
			initializeCharts();

			// Set up pause/resume button
			const pauseBtn = document.getElementById("pauseResumeBtn");
			if (pauseBtn) {
				pauseBtn.addEventListener("click", togglePauseResume);
			}

			// Start polling
			startPolling();
		},
		{ once: true },
	);
})();
