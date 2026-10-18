/**
 * Projects & Programmes Main Page JavaScript
 * Ministry of Health — State Department for Medical Services (PP-SDOMS)
 * Vanilla JavaScript / Fetch API / Chart.js (Zero Node.js)
 */

document.addEventListener('DOMContentLoaded', () => {
    let currentPage = 1;
    const limit = 6;
    let currentStatus = 'All';
    let currentCategory = 'All';
    let ganttChartInstance = null;
    let statusChartInstance = null;

    const projectsTableBody = document.getElementById('projectsTableBody');
    const loadMoreBtn = document.getElementById('loadMoreBtn');
    const searchInput = document.getElementById('projectSearchInput');
    const filterSaga = document.getElementById('filterSaga');
    const filterDirectorate = document.getElementById('filterDirectorate');
    const filterDivision = document.getElementById('filterDivision');
    const filterDeliveryUnit = document.getElementById('filterDeliveryUnit');
    const filterPriority = document.getElementById('filterPriority');
    const statusPills = document.querySelectorAll('.status-pill');

    // 1. Fetch & Render Projects
    async function loadProjects(page = 1, append = false) {
        if (!projectsTableBody) return;

        const params = new URLSearchParams({
            page: page,
            limit: limit,
            search: searchInput ? searchInput.value.trim() : '',
            status: currentStatus,
            category: currentCategory,
            saga_id: filterSaga && filterSaga.value !== 'All' ? filterSaga.value : '',
            directorate_id: filterDirectorate && filterDirectorate.value !== 'All' ? filterDirectorate.value : '',
            division_id: filterDivision && filterDivision.value !== 'All' ? filterDivision.value : '',
            delivery_unit: filterDeliveryUnit && filterDeliveryUnit.value !== 'All' ? filterDeliveryUnit.value : '',
            priority: filterPriority && filterPriority.value !== 'All' ? filterPriority.value : ''
        });

        if (!append) {
            projectsTableBody.innerHTML = `
                <tr>
                    <td colspan="7" style="text-align: center; padding: 30px; color: #64748b;">
                        <div style="display: inline-block; animation: spin 1s linear infinite;">⏳</div> Loading priority projects...
                    </td>
                </tr>
            `;
        }

        try {
            const res = await fetch(window.appUrl(`api/projects.php?${params.toString()}`));
            const data = await res.json();

            if (!data.success) {
                projectsTableBody.innerHTML = `<tr><td colspan="7" style="color: #ef4444; padding: 20px; text-align: center;">Error: ${data.error}</td></tr>`;
                return;
            }

            const projects = data.projects;
            if (!append) {
                projectsTableBody.innerHTML = '';
            }

            if (projects.length === 0 && !append) {
                projectsTableBody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 30px; color: #64748b;">No projects found matching the current filters.</td></tr>`;
                if (loadMoreBtn) loadMoreBtn.style.display = 'none';
                return;
            }

            projects.forEach(p => {
                const tr = document.createElement('tr');
                tr.className = p.row_class || 'status-row-default';

                const locationText = p.location ? p.location : 'Countrywide';
                const categoryLabel = p.category ? p.category : 'Directorate';
                const typeLabel = p.type ? p.type : 'Project';
                const progressNum = p.progress_pct_clean;

                tr.innerHTML = `
                    <td class="project-title-cell">
                        <a href="${window.appUrl('project-view.php?id=' + p.id)}" class="title-text" style="text-decoration: none; color: inherit;">
                            ${escapeHTML(p.title)}
                        </a>
                        <div class="location-subtext">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                            <span>${escapeHTML(locationText)}</span>
                        </div>
                    </td>
                    <td>
                        <span class="badge-type">${escapeHTML(typeLabel)}</span>
                    </td>
                    <td>
                        <span style="font-weight: 600; font-size: 0.8rem; color: #334155;">${escapeHTML(categoryLabel)}</span>
                    </td>
                    <td>
                        <span class="badge-status ${p.badge_class}">${escapeHTML(p.status)}</span>
                    </td>
                    <td class="budget-val">
                        ${escapeHTML(p.total_budget_formatted)}
                    </td>
                    <td class="progress-cell">
                        <div class="progress-bar-wrap">
                            <div class="progress-track">
                                <div class="progress-fill" style="width: ${Math.min(100, progressNum)}%;"></div>
                            </div>
                            <span class="progress-text">${progressNum}%</span>
                        </div>
                    </td>
                    <td class="actions-cell">
                        <a href="${window.appUrl('project-view.php?id=' + p.id)}" class="btn-icon-action" title="View Project Details">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                        </a>
                        <button class="btn-icon-action" onclick="openEditProjectModal(${p.id})" title="Edit Project">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                        </button>
                    </td>
                `;
                projectsTableBody.appendChild(tr);
            });

            // Handle Lazy Load Button
            if (loadMoreBtn) {
                if (data.pagination.has_more) {
                    loadMoreBtn.style.display = 'inline-flex';
                    loadMoreBtn.textContent = `Load More Projects (${data.pagination.total - (page * limit)} remaining)`;
                } else {
                    loadMoreBtn.style.display = 'none';
                }
            }
        } catch (err) {
            console.error('Failed to load projects:', err);
            if (!append) {
                projectsTableBody.innerHTML = `<tr><td colspan="7" style="color: #ef4444; padding: 20px; text-align: center;">Network error loading projects.</td></tr>`;
            }
        }
    }

    // 2. Load More Button Click (Lazy Loading)
    if (loadMoreBtn) {
        loadMoreBtn.addEventListener('click', () => {
            currentPage++;
            loadProjects(currentPage, true);
        });
    }

    // 3. Status Filter Pills Click
    statusPills.forEach(pill => {
        pill.addEventListener('click', (e) => {
            e.preventDefault();
            statusPills.forEach(p => p.classList.remove('active'));
            pill.classList.add('active');
            currentStatus = pill.getAttribute('data-status') || 'All';
            currentPage = 1;
            loadProjects(1, false);
        });
    });

    // 4. Multi-Filter Toolbar Change Handlers
    [filterSaga, filterDirectorate, filterDivision, filterDeliveryUnit, filterPriority].forEach(sel => {
        if (sel) {
            sel.addEventListener('change', () => {
                currentPage = 1;
                loadProjects(1, false);
            });
        }
    });

    // Search Input debounce
    if (searchInput) {
        let debounceTimer;
        searchInput.addEventListener('input', () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                currentPage = 1;
                loadProjects(1, false);
            }, 300);
        });
    }

    // 5. Initialize Performance Charts (Gantt & Portfolio Visualizations)
    async function initPerformanceCharts() {
        try {
            const res = await fetch(window.appUrl('api/performance-summary.php'));
            const data = await res.json();
            if (!data.success) return;

            // Render Gantt Timeline Chart
            const ganttCtx = document.getElementById('portfolioGanttChart');
            if (ganttCtx && typeof Chart !== 'undefined') {
                const labels = data.gantt_projects.map(p => p.title.length > 28 ? p.title.substring(0, 26) + '...' : p.title);
                const progressData = data.gantt_projects.map(p => p.progress_pct_clean);
                const backgroundColors = data.gantt_projects.map(p => {
                    const st = p.status.toLowerCase();
                    if (st === 'completed') return '#10b981';
                    if (st === 'in progress') return '#1976d2';
                    if (st === 'delayed' || st === 'overdue') return '#ef4444';
                    return '#f59e0b';
                });

                if (ganttChartInstance) ganttChartInstance.destroy();

                ganttChartInstance = new Chart(ganttCtx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Overall Progress %',
                            data: progressData,
                            backgroundColor: backgroundColors,
                            borderRadius: 6,
                            barThickness: 16
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    afterLabel: (ctx) => {
                                        const p = data.gantt_projects[ctx.dataIndex];
                                        return `Status: ${p.status}\nTimeline: ${p.start_formatted} - ${p.end_formatted}\nTask: ${p.gantt_task_name || 'In Progress'}`;
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                min: 0,
                                max: 100,
                                ticks: { callback: v => v + '%' },
                                grid: { color: '#e2e8f0' }
                            },
                            y: {
                                ticks: { font: { size: 11, weight: '600' } },
                                grid: { display: false }
                            }
                        }
                    }
                });
            }

            // Render Status Distribution Doughnut Chart
            const statusCtx = document.getElementById('portfolioStatusChart');
            if (statusCtx && typeof Chart !== 'undefined') {
                const statusLabels = data.status_distribution.map(s => s.status);
                const statusCounts = data.status_distribution.map(s => s.count);
                const colorMap = {
                    'In Progress': '#1976d2',
                    'Completed': '#10b981',
                    'On Hold': '#f59e0b',
                    'Planning': '#8b5cf6',
                    'Delayed': '#f97316',
                    'Overdue': '#ef4444',
                    'Cancelled': '#94a3b8'
                };
                const bgColors = statusLabels.map(s => colorMap[s] || '#64748b');

                if (statusChartInstance) statusChartInstance.destroy();

                statusChartInstance = new Chart(statusCtx, {
                    type: 'doughnut',
                    data: {
                        labels: statusLabels,
                        datasets: [{
                            data: statusCounts,
                            backgroundColor: bgColors,
                            borderWidth: 2,
                            borderColor: '#ffffff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right',
                                labels: { font: { size: 11, weight: '600' }, boxWidth: 14 }
                            }
                        },
                        cutout: '65%'
                    }
                });
            }
        } catch (err) {
            console.error('Failed to init performance charts:', err);
        }
    }

    // Initial Load
    loadProjects(1, false);
    initPerformanceCharts();
});

// Modal Helpers
function openNewProjectModal() {
    const modal = document.getElementById('newProjectModal');
    if (modal) modal.classList.add('active');
}

function closeNewProjectModal() {
    const modal = document.getElementById('newProjectModal');
    if (modal) modal.classList.remove('active');
}

// Global Edit Project Modal stub
window.openEditProjectModal = function(id) {
    window.location.href = window.appUrl(`project-view.php?id=${id}#kpi-section`);
};

// Safe HTML Escape
function escapeHTML(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
