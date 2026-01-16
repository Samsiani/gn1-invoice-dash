jQuery(function ($) {
  'use strict';

  // --- GLOBAL VARIABLES ---
  var currentUser = null;
  var usersData = [];
  var invoicesData = [];
  var currentFilters = {
    date_from: '',
    date_to: '',
    payment_method: '', 
    status: 'standard', // Default: Active
    search: ''
  };
  
  // Pagination Configs
  var usersPagination = { current_page: 1, per_page: 20, total_pages: 1 };
  var invoicesPagination = { current_page: 1, per_page: 30, total_pages: 1 };
  var currentSort = { column: 'invoice_count', order: 'desc' };
  
  // Auto Refresh
  var autoRefreshInterval = null;
  var paymentChart = null;

  // Product Insight Filters
  var currentPiFilter = { productId: 0, dateFrom: '', dateTo: '', range: 'all_time' };

  // Customer Insight Variables
  var custPagination = { current_page: 1, per_page: 20, total_pages: 1 };
  var custFilters = { search: '', dateFrom: '', dateTo: '' };

  // External Balance Variables (NEW)
  var extFilters = { dateFrom: '', dateTo: '' };

  // Top Products Variables
  var topProductsFilters = { dateFrom: '', dateTo: '', search: '' };

  // --- INITIALIZATION ---
  $(document).ready(function() {
    initializeFilters();
    
    // Load active tab data
    var activeTab = $('.nav-tab-active').data('tab');
    if (activeTab === 'overview') loadStatistics(true);
    else if (activeTab === 'external') loadExternalBalance();
    else if (activeTab === 'customer') loadCustomers();
    else if (activeTab === 'product') loadTopProducts();

    startAutoRefresh();
    bindEvents();
    
    // Initialize Product Search for Product Tab
    initProductSearch();
  });

  function initializeFilters() {
    var savedSort = localStorage.getItem('cig_stats_sort');
    if (savedSort) { try { currentSort = JSON.parse(savedSort); } catch(e) {} }
    var savedPerPage = localStorage.getItem('cig_stats_per_page');
    if (savedPerPage) {
      usersPagination.per_page = parseInt(savedPerPage, 10);
      $('#cig-users-per-page').val(savedPerPage);
    }
  }

  function bindEvents() {
    // --- TABS NAVIGATION ---
    $(document).on('click', '.nav-tab', function(e) {
        e.preventDefault();
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $('.cig-tab-content').hide();
        var tab = $(this).data('tab');
        $('#cig-tab-' + tab).show();

        // Load Tab Data on Click
        if (tab === 'customer' && $('#cig-customers-tbody tr.loading-row').length > 0) {
            loadCustomers();
        } else if (tab === 'external') {
            loadExternalBalance();
        } else if (tab === 'product') {
            loadTopProducts();
        }
    });

    // --- OVERVIEW FILTERS ---
    $(document).on('click', '.cig-quick-filter-btn', handleQuickFilter);
    $(document).on('click', '#cig-apply-date-range', applyCustomDateRange);
    
    $(document).on('change', '#cig-payment-filter', function() {
      currentFilters.payment_method = $(this).val();
      clearSummaryDropdowns();
      hideUserDetail();
      loadStatistics(true);
    });

    $(document).on('change', '#cig-status-filter', function() {
      currentFilters.status = $(this).val();
      clearSummaryDropdowns();
      hideUserDetail();
      loadStatistics(true);
    });

    $(document).on('click', '#cig-refresh-stats', function() {
      var tab = $('.nav-tab-active').data('tab');
      if (tab === 'overview') {
          clearSummaryDropdowns();
          hideUserDetail();
          loadStatistics(true);
      } else if (tab === 'customer') {
          loadCustomers();
      } else if (tab === 'external') {
          loadExternalBalance();
      } else if (tab === 'product') {
          loadTopProducts();
          if (currentPiFilter.productId) {
              loadProductInsight(currentPiFilter.productId);
          }
      }
    });
    
    $(document).on('click', '#cig-export-stats', handleExport);

    // --- OVERVIEW SEARCH BAR ---
    var overviewSearchTimeout;
    $(document).on('input', '#cig-overview-search', function() {
      clearTimeout(overviewSearchTimeout);
      var term = $(this).val();
      overviewSearchTimeout = setTimeout(function() {
        currentFilters.search = term;
        clearSummaryDropdowns();
        loadSummary(true);
      }, 400);
    });

    var searchTimeout;
    $(document).on('input', '#cig-user-search', function() {
      clearTimeout(searchTimeout);
      var term = $(this).val();
      searchTimeout = setTimeout(function() {
        currentFilters.search = term;
        filterUsers();
      }, 300);
    });

    $(document).on('change', '#cig-users-per-page', function() {
      usersPagination.per_page = parseInt($(this).val(), 10);
      usersPagination.current_page = 1;
      localStorage.setItem('cig_stats_per_page', $(this).val());
      displayUsersPage();
    });

    // --- USERS TABLE INTERACTION ---
    $(document).on('click', '#cig-users-table tbody tr.cig-user-row', function(e) {
      if ($(this).hasClass('no-results-row') || $(this).hasClass('loading-row')) return;
      var userId = $(this).data('user-id');
      if (userId) showUserDetail(userId);
    });

    $(document).on('click', '#cig-users-table .sortable', handleSort);

    // --- USER DETAIL VIEW ---
    $(document).on('click', '#cig-back-to-users', function() {
      hideUserDetail();
    });

    var invoiceSearchTimeout;
    $(document).on('input', '#cig-invoice-search', function() {
      clearTimeout(invoiceSearchTimeout);
      var term = $(this).val();
      invoiceSearchTimeout = setTimeout(function() {
        currentFilters.search = term;
        filterUserInvoices();
      }, 300);
    });

    $(document).on('change', '#cig-user-payment-filter', function() {
      currentFilters.payment_method = $(this).val();
      if (currentUser) loadUserInvoices(currentUser.user_id);
    });

    $(document).on('change', '#cig-invoices-per-page', function() {
      invoicesPagination.per_page = parseInt($(this).val(), 10);
      invoicesPagination.current_page = 1;
      displayInvoicesPage();
    });

    // --- PAGINATION (Shared Logic) ---
    $(document).on('click', '.cig-page-btn', function() {
      if ($(this).is(':disabled') || $(this).hasClass('active')) return;
      var page = parseInt($(this).data('page'), 10);
      
      if ($(this).closest('#cig-users-pagination').length) {
        usersPagination.current_page = page;
        displayUsersPage();
      } else if ($(this).closest('#cig-invoices-pagination').length) {
        invoicesPagination.current_page = page;
        displayInvoicesPage();
      } else if ($(this).hasClass('cig-cust-page-btn')) { // Customer Pagination
        custPagination.current_page = page;
        loadCustomers();
      }
    });

    // --- SUMMARY CARDS CLICK ---
    $(document).on('click', '.cig-stat-card[data-dropdown="invoices"], .cig-stat-card[data-dropdown="outstanding"]', function() {
        var dropdownType = $(this).data('dropdown');
        var method = $(this).data('method'); 
        var cardTitle = $(this).find('.cig-stat-label').text();
        
        currentFilters.payment_method = method; 
        
        if (dropdownType === 'outstanding') {
            toggleOutstandingDropdown(cardTitle);
        } else {
            toggleInvoicesDropdown(method, cardTitle);
        }
    });

    $(document).on('click', '#cig-card-products-sold', function() {
      toggleProductsDropdown('sold');
    });
    $(document).on('click', '#cig-card-products-reserved', function() {
      toggleProductsDropdown('reserved');
    });

    $(document).on('click', '.cig-summary-close', function() {
      var target = $(this).data('target');
      $(target).slideUp(150);
    });

    // --- PRODUCT INSIGHT FILTERS ---
    $(document).on('click', '.cig-pi-filter-btn', function() {
         $('.cig-pi-filter-btn').removeClass('active');
         $(this).addClass('active');
         var range = $(this).data('filter');
         currentPiFilter.range = range;
         
         var today = new Date();
         var from = '', to = '';
         if(range === 'today') {
             from = formatDate(today); to = formatDate(today);
         } else if(range === 'this_week') {
             var ws = new Date(today); var day = ws.getDay(); var diff = (day === 0 ? -6 : 1) - day;
             ws.setDate(ws.getDate() + diff); from = formatDate(ws); to = formatDate(today);
         } else if(range === 'this_month') {
             from = formatDate(new Date(today.getFullYear(), today.getMonth(), 1));
             to = formatDate(today);
         } else if(range === 'last_30_days') {
             var p30 = new Date(today); p30.setDate(today.getDate() - 30);
             from = formatDate(p30); to = formatDate(today);
         }
         
         $('#cig-pi-date-from').val(from);
         $('#cig-pi-date-to').val(to);
         currentPiFilter.dateFrom = from;
         currentPiFilter.dateTo = to;
         
         if(currentPiFilter.productId) loadProductInsight(currentPiFilter.productId);
    });

    $(document).on('click', '#cig-pi-apply-date', function() {
         var f = $('#cig-pi-date-from').val();
         var t = $('#cig-pi-date-to').val();
         if(f && t) {
             $('.cig-pi-filter-btn').removeClass('active');
             currentPiFilter.dateFrom = f;
             currentPiFilter.dateTo = t;
             if(currentPiFilter.productId) loadProductInsight(currentPiFilter.productId);
         }
    });

    // --- CUSTOMER INSIGHT EVENTS ---
    var custSearchTimeout;
    $(document).on('input', '#cig-customer-search', function() {
        clearTimeout(custSearchTimeout);
        var val = $(this).val();
        custSearchTimeout = setTimeout(function() {
            custFilters.search = val;
            custPagination.current_page = 1;
            loadCustomers();
        }, 400);
    });

    $(document).on('click', '#cig-cust-apply-date', function() {
        custFilters.dateFrom = $('#cig-cust-date-from').val();
        custFilters.dateTo = $('#cig-cust-date-to').val();
        custPagination.current_page = 1;
        loadCustomers();
    });

    // Customer row click - drill-down to invoices
    $(document).on('click', '.cig-customer-row', function(e) {
        e.preventDefault();
        var custId = $(this).data('customer-id');
        if (custId) {
            showCustomerDetail(custId);
        }
    });

    // Keep old handler for backward compatibility
    $(document).on('click', '.cig-cust-tax-link', function(e) {
        e.preventDefault();
        var custId = $(this).data('id');
        showCustomerDetail(custId);
    });

    $(document).on('click', '#cig-back-to-customers', function() {
        $('#cig-customer-detail-panel').slideUp();
        $('#cig-customer-list-panel').slideDown();
    });

    // --- EXTERNAL BALANCE EVENTS (NEW) ---
    $(document).on('click', '#cig-ext-apply-date', function() {
        extFilters.dateFrom = $('#cig-ext-date-from').val();
        extFilters.dateTo = $('#cig-ext-date-to').val();
        loadExternalBalance();
    });

    $(document).on('click', '#cig-btn-add-deposit', function() {
        $('#cig-deposit-modal').fadeIn(200);
    });

    $(document).on('click', '#cig-close-deposit-modal', function() {
        $('#cig-deposit-modal').fadeOut(200);
    });

    $(document).on('click', '#cig-submit-deposit', function() {
        submitDeposit();
    });

    $(document).on('click', '.cig-btn-delete-deposit', function() {
        if(!confirm('Are you sure you want to delete this record?')) return;
        deleteDeposit($(this).data('id'));
    });

    // --- TOP PRODUCTS EVENTS ---
    $(document).on('click', '#cig-tp-apply-filters', function() {
        topProductsFilters.dateFrom = $('#cig-tp-date-from').val();
        topProductsFilters.dateTo = $('#cig-tp-date-to').val();
        topProductsFilters.search = $('#cig-tp-search').val();
        loadTopProducts();
    });

    // Search on Enter key
    $(document).on('keypress', '#cig-tp-search', function(e) {
        if (e.which === 13) {
            topProductsFilters.dateFrom = $('#cig-tp-date-from').val();
            topProductsFilters.dateTo = $('#cig-tp-date-to').val();
            topProductsFilters.search = $(this).val();
            loadTopProducts();
        }
    });
  }

  // --- EXTERNAL BALANCE LOGIC ---
  function loadExternalBalance() {
      // Show loading state
      $('#cig-ext-accumulated').text('...');
      $('#cig-ext-deposited').text('...');
      $('#cig-ext-balance').text('...');
      $('#cig-ext-history-tbody').html('<tr class="loading-row"><td colspan="4"><div class="cig-loading-spinner"><div class="spinner"></div><p>Loading...</p></div></td></tr>');

      $.ajax({
          url: cigStats.ajax_url,
          method: 'POST',
          dataType: 'json',
          data: {
              action: 'cig_get_external_balance',
              nonce: cigStats.nonce,
              start_date: extFilters.dateFrom,
              end_date: extFilters.dateTo
          },
          success: function(res) {
              if (res.success && res.data) {
                  // Update Cards
                  $('#cig-ext-accumulated').html(formatCurrency(res.data.cards.accumulated));
                  $('#cig-ext-deposited').html(formatCurrency(res.data.cards.deposited));
                  
                  var bal = parseFloat(res.data.cards.balance);
                  var balHtml = formatCurrency(bal);
                  if(bal < -0.01) {
                      // Negative means Debt/Due
                      $('#cig-ext-balance').html('<span style="color:#dc3545;">' + balHtml + '</span>');
                  } else {
                      $('#cig-ext-balance').html('<span style="color:#28a745;">' + balHtml + '</span>');
                  }

                  // Update Table
                  renderDepositHistory(res.data.history);
              }
          },
          error: function() {
              alert('Error loading external balance data.');
          }
      });
  }

  function renderDepositHistory(history) {
      if (!history || !history.length) {
          $('#cig-ext-history-tbody').html('<tr><td colspan="4" style="text-align:center; padding:20px; color:#999;">No deposit history found.</td></tr>');
          return;
      }

      var html = '';
      history.forEach(function(row) {
          html += '<tr>';
          html += '<td>' + row.date + '</td>';
          html += '<td>' + escapeHtml(row.comment || '—') + '</td>';
          html += '<td style="text-align:right; font-weight:bold; color:#28a745;">' + formatCurrency(row.amount) + '</td>';
          html += '<td style="text-align:center;"><button type="button" class="button cig-btn-delete-deposit" data-id="' + row.id + '" style="color:#dc3545; border:none; background:transparent;"><span class="dashicons dashicons-trash"></span></button></td>';
          html += '</tr>';
      });
      $('#cig-ext-history-tbody').html(html);
  }

  function submitDeposit() {
      var amount = $('#cig-dep-amount').val();
      var date = $('#cig-dep-date').val();
      var note = $('#cig-dep-note').val();

      if (!amount || parseFloat(amount) <= 0) {
          alert('Please enter a valid amount.');
          return;
      }
      if (!date) {
          alert('Please select a date.');
          return;
      }

      var $btn = $('#cig-submit-deposit');
      $btn.prop('disabled', true).text('Saving...');

      $.ajax({
          url: cigStats.ajax_url,
          method: 'POST',
          dataType: 'json',
          data: {
              action: 'cig_add_deposit',
              nonce: cigStats.nonce,
              amount: amount,
              date: date,
              note: note
          },
          success: function(res) {
              $btn.prop('disabled', false).text('Confirm');
              if (res.success) {
                  $('#cig-deposit-modal').fadeOut();
                  $('#cig-dep-amount').val('');
                  $('#cig-dep-note').val('');
                  loadExternalBalance(); // Refresh
              } else {
                  alert(res.data.message || 'Error saving deposit.');
              }
          },
          error: function() {
              $btn.prop('disabled', false).text('Confirm');
              alert('Server error.');
          }
      });
  }

  function deleteDeposit(id) {
      $.ajax({
          url: cigStats.ajax_url,
          method: 'POST',
          dataType: 'json',
          data: {
              action: 'cig_delete_deposit',
              nonce: cigStats.nonce,
              id: id
          },
          success: function(res) {
              if (res.success) {
                  loadExternalBalance();
              } else {
                  alert(res.data.message);
              }
          }
      });
  }

  // --- TOP SELLING PRODUCTS LOGIC ---

  /**
   * Load Top Selling Products table
   * Fetches data from cig_get_top_products AJAX action
   */
  function loadTopProducts() {
      // Show loading state
      $('#cig-top-products-tbody').html('<tr class="loading-row"><td colspan="5"><div class="cig-loading-spinner"><div class="spinner"></div><p>Loading top products...</p></div></td></tr>');

      $.ajax({
          url: cigStats.ajax_url,
          method: 'POST',
          dataType: 'json',
          data: {
              action: 'cig_get_top_products',
              nonce: cigStats.nonce,
              date_from: topProductsFilters.dateFrom,
              date_to: topProductsFilters.dateTo,
              search: topProductsFilters.search
          },
          success: function(res) {
              if (res.success && res.data && res.data.products) {
                  renderTopProductsTable(res.data.products);
              } else {
                  $('#cig-top-products-tbody').html('<tr><td colspan="5" style="text-align:center; padding:20px; color:#999;">No products found</td></tr>');
              }
          },
          error: function() {
              $('#cig-top-products-tbody').html('<tr><td colspan="5" style="text-align:center; padding:20px; color:#dc3545;">Error loading products</td></tr>');
          }
      });
  }

  /**
   * Render Top Selling Products table rows
   */
  function renderTopProductsTable(products) {
      if (!products || !products.length) {
          $('#cig-top-products-tbody').html('<tr><td colspan="5" style="text-align:center; padding:20px; color:#999;">No products found</td></tr>');
          return;
      }

      var html = '';
      products.forEach(function(product) {
          html += '<tr>';
          html += '<td><strong>' + escapeHtml(product.product_name || '—') + '</strong></td>';
          html += '<td>' + escapeHtml(product.sku || '—') + '</td>';
          html += '<td>' + formatCurrency(product.price) + '</td>';
          html += '<td><span class="cig-badge badge-sold">' + formatNumber(product.sold_qty) + '</span></td>';
          html += '<td><strong style="color:#28a745;">' + formatCurrency(product.total_revenue) + '</strong></td>';
          html += '</tr>';
      });
      $('#cig-top-products-tbody').html(html);
  }

  // --- OVERVIEW LOGIC ---

  function handleQuickFilter() {
    $('.cig-quick-filter-btn').removeClass('active');
    $(this).addClass('active');
    var filter = $(this).data('filter');
    var today = new Date();
    var from = '', to = '';
    switch(filter) {
      case 'today': from = to = formatDate(today); break;
      case 'this_week': var ws = new Date(today); ws.setDate(ws.getDate() + ((ws.getDay()===0 ? -6:1) - ws.getDay())); from = formatDate(ws); to = formatDate(today); break;
      case 'this_month': from = formatDate(new Date(today.getFullYear(), today.getMonth(), 1)); to = formatDate(today); break;
      case 'last_30_days': var p30 = new Date(today); p30.setDate(today.getDate() - 30); from = formatDate(p30); to = formatDate(today); break;
      case 'all_time': from = ''; to = ''; break;
    }
    $('#cig-date-from').val(from); $('#cig-date-to').val(to);
    currentFilters.date_from = from; currentFilters.date_to = to;
    clearSummaryDropdowns(); hideUserDetail(); loadStatistics(true);
  }

  function applyCustomDateRange() {
    var from = $('#cig-date-from').val();
    var to = $('#cig-date-to').val();
    if (!from || !to) { alert('Please select both dates'); return; }
    $('.cig-quick-filter-btn').removeClass('active');
    currentFilters.date_from = from; currentFilters.date_to = to;
    clearSummaryDropdowns(); hideUserDetail(); loadStatistics(true);
  }

  function loadStatistics(force) { loadSummary(force); loadUsers(force); }

  function loadSummary(force) {
    if (force) {
      $('.cig-stat-value').html('<span class="loading-stat">...</span>');
      $('#cig-payment-chart-empty').hide();
    }
    $.ajax({
      url: cigStats.ajax_url, method: 'POST', dataType: 'json',
      data: { 
          action: 'cig_get_statistics_summary', 
          nonce: cigStats.nonce, 
          date_from: currentFilters.date_from, 
          date_to: currentFilters.date_to, 
          payment_method: $('#cig-payment-filter').val(), // Global filter if any
          status: currentFilters.status,
          search: currentFilters.search
      },
      success: function(res) {
        if (res && res.success && res.data) { 
            updateSummaryCards(res.data); 
            updatePaymentChart(res.data);
        } else { 
            showSummaryEmpty(); 
        }
      },
      error: function(){ showSummaryEmpty(); }
    });
  }

  function showSummaryEmpty() {
    $('.cig-stat-value').text('0');
    $('#cig-payment-chart-empty').show();
    if (paymentChart) { paymentChart.destroy(); paymentChart = null; }
  }

  function updateSummaryCards(data) {
    $('#stat-total-invoices').html(formatNumber(data.total_invoices));
    $('#stat-total-revenue').html(formatCurrency(data.total_revenue));
    $('#stat-total-paid').html(formatCurrency(data.total_paid));
    $('#stat-total-outstanding').html(formatCurrency(data.total_outstanding));
    $('#stat-total-reserved-invoices').html(formatNumber(data.total_reserved_invoices) + ' ინვოისი');
    $('#stat-total-reserved-invoices').html(formatNumber(data.total_reserved_invoices));
    
    // Payment Methods
    $('#stat-total-cash').html(formatCurrency(data.total_cash));
    $('#stat-total-company_transfer').html(formatCurrency(data.total_company_transfer));
    $('#stat-total-credit').html(formatCurrency(data.total_credit));
    $('#stat-total-consignment').html(formatCurrency(data.total_consignment));
    $('#stat-total-other').html(formatCurrency(data.total_other));
    
    // Legacy products stats
    $('#stat-products-sold').html(formatNumber(data.total_sold));
    $('#stat-products-reserved').html(formatNumber(data.total_reserved));
  }

  function updatePaymentChart(data) {
    var $empty = $('#cig-payment-chart-empty');
    var labels = ['ჩარიცხვა', 'ქეში', 'განვადება', 'კონსიგნაცია', 'სხვა'];
    var values = [
        data.total_company_transfer,
        data.total_cash,
        data.total_credit,
        data.total_consignment,
        data.total_other
    ];
    
    var colors = [
        cigStats.colors.info, 
        cigStats.colors.success, 
        '#6c757d', 
        cigStats.colors.warning, 
        '#343a40'
    ];

    var totalVal = values.reduce((a, b) => a + b, 0);
    
    var ctx = document.getElementById('cig-payment-chart');
    if (!ctx) return;
    if (paymentChart) { paymentChart.destroy(); paymentChart = null; }
    
    if (totalVal <= 0.01) { $empty.show(); return; }
    $empty.hide();
    
    paymentChart = new Chart(ctx, {
      type: 'doughnut',
      data: { labels: labels, datasets: [{ data: values, backgroundColor: colors, borderWidth: 2, borderColor: '#fff' }] },
      options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom', labels: { padding: 15, font: { size: 12 } } }, tooltip: { callbacks: { label: function(c){ return (c.label||'') + ': ' + formatCurrency(c.parsed); } } } } }
    });
  }

  function loadUsers(force) {
    if (force) { $('#cig-users-tbody').html('<tr class="loading-row"><td colspan="7"><div class="cig-loading-spinner"><div class="spinner"></div><p>Loading users...</p></div></td></tr>'); }
    $.ajax({
      url: cigStats.ajax_url, method: 'POST', dataType: 'json',
      data: { action: 'cig_get_users_statistics', nonce: cigStats.nonce, date_from: currentFilters.date_from, date_to: currentFilters.date_to, search: currentFilters.search, sort_by: currentSort.column, sort_order: currentSort.order, status: currentFilters.status },
      success: function(res) { if (res && res.success && res.data) { usersData = res.data.users; filterUsers(); } else { $('#cig-users-tbody').html('<tr class="no-results-row"><td colspan="7">No users found</td></tr>'); } },
      error: function() { $('#cig-users-tbody').html('<tr class="no-results-row"><td colspan="7" style="color:#dc3545;">Error loading users</td></tr>'); }
    });
  }

  function filterUsers() {
    var filtered = usersData;
    if (currentFilters.search) { var term = currentFilters.search.toLowerCase(); filtered = usersData.filter(function(u){ return u.user_name.toLowerCase().includes(term) || u.user_email.toLowerCase().includes(term); }); }
    usersPagination.current_page = 1;
    usersPagination.total_pages = Math.ceil(filtered.length / usersPagination.per_page);
    displayUsersPage(filtered);
  }

  function displayUsersPage(filtered) {
    filtered = filtered || usersData;
    usersPagination.total_pages = Math.ceil(filtered.length / usersPagination.per_page);
    var start = (usersPagination.current_page - 1) * usersPagination.per_page;
    var end = start + usersPagination.per_page;
    var pageData = filtered.slice(start, end);
    if (!pageData.length) { $('#cig-users-tbody').html('<tr class="no-results-row"><td colspan="7">No users found</td></tr>'); $('#cig-users-pagination').html(''); return; }
    var html = '';
    pageData.forEach(function(user){
      html += '<tr class="cig-user-row" data-user-id="' + user.user_id + '">';
      html += '<td><div class="user-cell"><img src="' + user.user_avatar + '" alt="" class="user-avatar"><div class="user-info"><div class="user-name">' + escapeHtml(user.user_name) + '</div><div class="user-email">' + escapeHtml(user.user_email) + '</div></div></div></td>';
      html += '<td><strong>' + user.invoice_count + '</strong></td>';
      html += '<td><span class="cig-badge badge-sold">' + formatNumber(user.total_sold) + '</span></td>';
      html += '<td><span class="cig-badge badge-reserved">' + formatNumber(user.total_reserved) + '</span></td>';
      html += '<td><span class="cig-badge badge-canceled">' + formatNumber(user.total_canceled) + '</span></td>';
      html += '<td><strong>' + formatCurrency(user.total_revenue) + '</strong></td>';
      html += '<td>' + formatDateTime(user.last_invoice_date) + '</td>';
      html += '</tr>';
    });
    $('#cig-users-tbody').html(html); renderPagination('users'); updateSortArrows();
  }

  function handleSort() {
    var column = $(this).data('sort');
    if (currentSort.column === column) currentSort.order = currentSort.order === 'asc' ? 'desc' : 'asc'; else { currentSort.column = column; currentSort.order = 'desc'; }
    $(this).data('order', currentSort.order); localStorage.setItem('cig_stats_sort', JSON.stringify(currentSort)); sortUsers();
  }
  function sortUsers() { usersData.sort(function(a,b){ var aVal = a[currentSort.column]; var bVal = b[currentSort.column]; if (currentSort.order === 'asc') return aVal > bVal ? 1 : -1; return aVal < bVal ? 1 : -1; }); displayUsersPage(); }
  function updateSortArrows() { $('#cig-users-table .sortable').removeClass('active-sort').removeAttr('data-order'); var $active = $('#cig-users-table .sortable[data-sort="' + currentSort.column + '"]'); $active.addClass('active-sort').attr('data-order', currentSort.order); }

  function showUserDetail(userId) {
    var user = usersData.find(function(u){ return u.user_id == userId; });
    if (!user) return;
    currentUser = user; currentFilters.search = ''; $('#cig-invoice-search').val(''); $('#cig-user-payment-filter').val('all'); currentFilters.payment_method = 'all';
    var infoHtml = '<img src="' + user.user_avatar + '" alt="" class="user-info-avatar"><div class="user-info-details"><h3>' + escapeHtml(user.user_name) + '</h3><p class="user-info-email">' + escapeHtml(user.user_email) + '</p><div class="user-info-stats"><div class="user-info-stat"><span class="user-info-stat-label">Total Invoices</span><span class="user-info-stat-value">' + user.invoice_count + '</span></div><div class="user-info-stat"><span class="user-info-stat-label">Total Revenue</span><span class="user-info-stat-value">' + formatCurrency(user.total_revenue) + '</span></div><div class="user-info-stat"><span class="user-info-stat-label">Last Invoice</span><span class="user-info-stat-value">' + formatDateShort(user.last_invoice_date) + '</span></div></div></div>';
    $('#cig-user-info').html(infoHtml); $('#cig-user-detail-title').text(user.user_name + ' - Invoices'); $('#cig-users-panel').hide(); $('#cig-user-detail-panel').fadeIn(150); loadUserInvoices(user.user_id);
  }
  function hideUserDetail() { currentUser = null; invoicesData = []; $('#cig-user-detail-panel').hide(); $('#cig-users-panel').fadeIn(150); }
  function loadUserInvoices(userId) {
    $('#cig-user-invoices-tbody').html('<tr class="loading-row"><td colspan="9"><div class="cig-loading-spinner"><div class="spinner"></div><p>Loading invoices...</p></div></td></tr>');
    $.ajax({
      url: cigStats.ajax_url, method: 'POST', dataType: 'json',
      data: { action: 'cig_get_user_invoices', nonce: cigStats.nonce, user_id: userId, date_from: currentFilters.date_from, date_to: currentFilters.date_to, payment_method: currentFilters.payment_method === 'all' ? '' : currentFilters.payment_method, status: currentFilters.status, search: currentFilters.search },
      success: function(res) { if (res && res.success && res.data) { invoicesData = res.data.invoices; displayInvoicesPage(); } else { $('#cig-user-invoices-tbody').html('<tr class="no-results-row"><td colspan="9">No invoices found</td></tr>'); } },
      error: function() { $('#cig-user-invoices-tbody').html('<tr class="no-results-row"><td colspan="9" style="color:#dc3545;">Error loading invoices</td></tr>'); }
    });
  }
  function filterUserInvoices() { var filtered = invoicesData; if (currentFilters.search) { var term = currentFilters.search.toLowerCase(); filtered = invoicesData.filter(function(inv){ return String(inv.invoice_number || '').toLowerCase().includes(term); }); } invoicesPagination.current_page = 1; displayInvoicesPage(filtered); }
  function displayInvoicesPage(filtered) {
    filtered = filtered || invoicesData; invoicesPagination.total_pages = Math.ceil(filtered.length / invoicesPagination.per_page); var start = (invoicesPagination.current_page - 1) * invoicesPagination.per_page; var end = start + invoicesPagination.per_page; var pageData = filtered.slice(start, end);
    if (!pageData.length) { $('#cig-user-invoices-tbody').html('<tr class="no-results-row"><td colspan="9">No invoices found</td></tr>'); $('#cig-invoices-pagination').html(''); return; }
    var html = '';
    pageData.forEach(function(inv){
      var paymentClass = 'payment-' + inv.payment_type;
      html += '<tr><td><strong>' + escapeHtml(inv.invoice_number) + '</strong></td><td>' + formatDateTime(inv.date) + '</td><td>' + formatNumber(inv.total_products) + '</td><td><span class="cig-badge badge-sold">' + formatNumber(inv.sold_items) + '</span></td><td><span class="cig-badge badge-reserved">' + formatNumber(inv.reserved_items) + '</span></td><td><span class="cig-badge badge-canceled">' + formatNumber(inv.canceled_items) + '</span></td><td><strong>' + formatCurrency(inv.invoice_total) + '</strong></td><td><span class="badge-payment ' + paymentClass + '">' + escapeHtml(inv.payment_label) + '</span></td><td><a href="' + inv.view_url + '" class="cig-btn-sm cig-btn-view" target="_blank">ნახვა</a> <a href="' + inv.edit_url + '" class="cig-btn-sm cig-btn-edit" target="_blank">რედაქტირება</a></td></tr>';
    });
    $('#cig-user-invoices-tbody').html(html); renderPagination('invoices');
  }

  function clearSummaryDropdowns() { $('#cig-summary-invoices').hide(); $('#cig-summary-outstanding').hide(); $('#cig-summary-products').hide(); }

  // --- TOGGLE INVOICES DROPDOWN ---
  function toggleInvoicesDropdown(method, titleText) {
    var $panel = $('#cig-summary-invoices');
    if ($panel.is(':visible') && $panel.data('method') === method) { 
        $panel.slideUp(150); 
        return; 
    }
    
    $panel.data('method', method);
    
    $('#cig-summary-products').hide(); $('#cig-summary-outstanding').hide();
    $('#cig-summary-invoices-tbody').html('<tr class="loading-row"><td colspan="9"><div class="cig-loading-spinner"><div class="spinner"></div><p>Loading...</p></div></td></tr>');
    
    if(titleText) {
        $('#cig-summary-title').html('<strong>' + escapeHtml(titleText) + '</strong>');
    } else {
        $('#cig-summary-title').text('Invoices');
    }

    $panel.slideDown(150);
    
    $.ajax({
      url: cigStats.ajax_url, method: 'POST', dataType: 'json',
      data: { 
          action: 'cig_get_invoices_by_filters', 
          nonce: cigStats.nonce, 
          date_from: currentFilters.date_from, 
          date_to: currentFilters.date_to, 
          payment_method: method, // Pass specific method from card
          status: currentFilters.status,
          search: currentFilters.search
      },
      success: function(res) {
        if (res && res.success && res.data && res.data.invoices && res.data.invoices.length) {
          var html = '';
          res.data.invoices.forEach(function(inv){
            var paidClass = inv.paid > 0 ? 'color:#28a745;' : '';
            var dueClass = inv.due > 0.01 ? 'color:#dc3545;font-weight:bold;' : 'color:#999;';

            var paidHtml = formatCurrency(inv.paid || 0);
            if(inv.paid_breakdown) {
                paidHtml += inv.paid_breakdown;
            }

            html += '<tr>';
            html += '<td><strong>' + escapeHtml(inv.invoice_number || '') + '</strong></td>';
            html += '<td>' + escapeHtml(inv.customer) + '</td>';
            html += '<td>' + escapeHtml(inv.payment_methods) + '</td>';
            html += '<td><strong>' + formatCurrency(inv.total || 0) + '</strong></td>';
            html += '<td style="' + paidClass + '">' + paidHtml + '</td>';
            html += '<td style="' + dueClass + '">' + formatCurrency(inv.due || 0) + '</td>';
            html += '<td>' + formatDateTime(inv.date) + '</td>';
            html += '<td>' + escapeHtml(inv.author || '') + '</td>';
            html += '<td><a class="cig-btn-sm cig-btn-view" href="' + inv.view_url + '" target="_blank">ნახვა</a> <a class="cig-btn-sm cig-btn-edit" href="' + inv.edit_url + '" target="_blank">რედაქტირება</a></td>';
            html += '</tr>';
          });
          $('#cig-summary-invoices-tbody').html(html);
        } else { $('#cig-summary-invoices-tbody').html('<tr class="no-results-row"><td colspan="9">No invoices found</td></tr>'); }
      },
      error: function() { $('#cig-summary-invoices-tbody').html('<tr class="no-results-row"><td colspan="9" style="color:#dc3545;">Error loading invoices</td></tr>'); }
    });
  }

  // --- TOGGLE OUTSTANDING DROPDOWN ---
  function toggleOutstandingDropdown(titleText) {
      var $panel = $('#cig-summary-outstanding');
      if ($panel.is(':visible')) { $panel.slideUp(150); return; }
      
      if(titleText) {
          $('#cig-summary-outstanding .cig-summary-header h3').html('<strong>' + escapeHtml(titleText) + '</strong>');
      }

      $('#cig-summary-invoices').hide(); $('#cig-summary-products').hide();
      $('#cig-summary-outstanding-tbody').html('<tr class="loading-row"><td colspan="9"><div class="cig-loading-spinner"><div class="spinner"></div><p>Loading unpaid invoices...</p></div></td></tr>');
      $panel.slideDown(150);
      $.ajax({
          url: cigStats.ajax_url, method: 'POST', dataType: 'json',
          data: { action: 'cig_get_invoices_by_filters', nonce: cigStats.nonce, date_from: currentFilters.date_from, date_to: currentFilters.date_to, payment_method: currentFilters.payment_method, status: 'outstanding', search: currentFilters.search },
          success: function(res) {
              if (res && res.success && res.data && res.data.invoices && res.data.invoices.length) {
                  var html = '';
                  res.data.invoices.forEach(function(inv){
                      var paidHtml = formatCurrency(inv.paid || 0);
                      if(inv.paid_breakdown) {
                          paidHtml += inv.paid_breakdown;
                      }

                      html += '<tr>';
                      html += '<td><strong>' + escapeHtml(inv.invoice_number) + '</strong></td>';
                      html += '<td>' + escapeHtml(inv.customer) + '</td>';
                      html += '<td>' + escapeHtml(inv.payment_methods) + '</td>';
                      html += '<td>' + formatCurrency(inv.total) + '</td>';
                      html += '<td style="color:#28a745;">' + paidHtml + '</td>';
                      html += '<td style="color:#dc3545;font-weight:bold;">' + formatCurrency(inv.due) + '</td>';
                      html += '<td>' + escapeHtml(inv.author) + '</td>';
                      html += '<td>' + formatDateTime(inv.date) + '</td>';
                      html += '<td><a class="cig-btn-sm cig-btn-view" href="' + inv.view_url + '" target="_blank">ნახვა</a> <a class="cig-btn-sm cig-btn-edit" href="' + inv.edit_url + '" target="_blank">რედაქტირება</a></td>';
                      html += '</tr>';
                  });
                  $('#cig-summary-outstanding-tbody').html(html);
              } else { $('#cig-summary-outstanding-tbody').html('<tr class="no-results-row"><td colspan="9">No outstanding invoices found.</td></tr>'); }
          },
          error: function() { $('#cig-summary-outstanding-tbody').html('<tr class="no-results-row"><td colspan="9" style="color:#dc3545;">Error loading data.</td></tr>'); }
      });
  }

  function toggleProductsDropdown(status) {
    var $panel = $('#cig-summary-products');
    var title = status === 'reserved' ? 'Products Reserved' : 'Products Sold';
    $('#cig-summary-products-title').text(title); $('#cig-col-qty-label').text(status === 'reserved' ? 'Reserved Qty' : 'Quantity Sold');
    if ($panel.is(':visible') && $panel.data('status') === status) { $panel.slideUp(150); return; }
    $('#cig-summary-invoices').hide(); $('#cig-summary-outstanding').hide();
    $panel.data('status', status).slideDown(150);
    $('#cig-summary-products-tbody').html('<tr class="loading-row"><td colspan="8"><div class="cig-loading-spinner"><div class="spinner"></div><p>Loading...</p></div></td></tr>');
    $.ajax({
      url: cigStats.ajax_url, method: 'POST', dataType: 'json',
      data: { action: 'cig_get_products_by_filters', nonce: cigStats.nonce, date_from: currentFilters.date_from, date_to: currentFilters.date_to, status: status, payment_method: currentFilters.payment_method, invoice_status: currentFilters.status },
      success: function(res) {
        if (res && res.success && res.data && res.data.products && res.data.products.length) {
          var html = '';
          res.data.products.forEach(function(it){
            var img = it.image ? '<img src="' + it.image + '" alt="" style="width:48px;height:48px;object-fit:contain;border:1px solid #eee;border-radius:4px;background:#fff;">' : '';
            html += '<tr><td>' + img + '</td><td>' + escapeHtml(it.name || '') + '</td><td>' + escapeHtml(it.sku || '—') + '</td><td><strong>' + formatNumber(it.qty || 0) + '</strong></td><td>' + escapeHtml(it.invoice_number || '') + '</td><td>' + escapeHtml(it.author_name || '') + '</td><td>' + formatDateTime(it.date) + '</td><td><a class="cig-btn-sm cig-btn-view" href="' + it.view_url + '" target="_blank">ნახვა</a> <a class="cig-btn-sm cig-btn-edit" href="' + it.edit_url + '" target="_blank">რედაქტირება</a></td></tr>';
          });
          $('#cig-summary-products-tbody').html(html);
        } else { $('#cig-summary-products-tbody').html('<tr class="no-results-row"><td colspan="8">No products found</td></tr>'); }
      },
      error: function() { $('#cig-summary-products-tbody').html('<tr class="no-results-row"><td colspan="8" style="color:#dc3545;">Error loading products</td></tr>'); }
    });
  }

  function renderPagination(type) {
    var cfg = (type === 'users') ? usersPagination : invoicesPagination;
    var $container = (type === 'users') ? $('#cig-users-pagination') : $('#cig-invoices-pagination');
    if (cfg.total_pages <= 1) { $container.html(''); return; }
    var cp = cfg.current_page, tp = cfg.total_pages;
    var html = '<button class="cig-page-btn" data-page="' + (cp - 1) + '" ' + (cp <= 1 ? 'disabled' : '') + '>« Prev</button>';
    var startPage = Math.max(1, cp - 2); var endPage = Math.min(tp, cp + 2);
    if (startPage > 1) { html += '<button class="cig-page-btn" data-page="1">1</button>'; if (startPage > 2) html += '<span style="padding:0 5px;color:#999;">...</span>'; }
    for (var i = startPage; i <= endPage; i++) { html += '<button class="cig-page-btn ' + (i === cp ? 'active' : '') + '" data-page="' + i + '">' + i + '</button>'; }
    if (endPage < tp) { if (endPage < tp - 1) html += '<span style="padding:0 5px;color:#999;">...</span>'; html += '<button class="cig-page-btn" data-page="' + tp + '">' + tp + '</button>'; }
    html += '<button class="cig-page-btn" data-page="' + (cp + 1) + '" ' + (cp >= tp ? 'disabled' : '') + '>Next »</button>';
    $container.html(html);
  }

  function handleExport() {
    var base = cigStats.ajax_url.replace('admin-ajax.php', 'admin.php');
    var params = ['cig_export=statistics', '_wpnonce=' + encodeURIComponent(cigStats.export_nonce)];
    if (currentFilters.date_from) params.push('date_from=' + encodeURIComponent(currentFilters.date_from));
    if (currentFilters.date_to) params.push('date_to=' + encodeURIComponent(currentFilters.date_to));
    if (currentFilters.status) params.push('status=' + encodeURIComponent(currentFilters.status));
    window.location.href = base + '?' + params.join('&');
  }

  function startAutoRefresh() {
    if (autoRefreshInterval) clearInterval(autoRefreshInterval);
    autoRefreshInterval = setInterval(function() { 
        var activeTab = $('.nav-tab-active').data('tab');
        if (activeTab === 'overview') {
            loadStatistics(true); 
            if (currentUser) loadUserInvoices(currentUser.user_id);
        }
    }, 300000);
  }

  // --- CUSTOMER INSIGHT LOGIC ---

  function loadCustomers() {
      $('#cig-customers-tbody').html('<tr class="loading-row"><td colspan="6"><div class="cig-loading-spinner"><div class="spinner"></div><p>Loading customers...</p></div></td></tr>');
      
      $.ajax({
          url: cigStats.ajax_url,
          method: 'POST',
          dataType: 'json',
          data: {
              action: 'cig_get_customer_insights',
              nonce: cigStats.nonce,
              paged: custPagination.current_page,
              per_page: custPagination.per_page,
              search: custFilters.search,
              date_from: custFilters.dateFrom,
              date_to: custFilters.dateTo
          },
          success: function(res) {
              if(res.success && res.data) {
                  renderCustomerTable(res.data.customers);
                  custPagination.total_pages = res.data.total_pages; 
                  renderCustomerPagination();
              } else {
                  $('#cig-customers-tbody').html('<tr><td colspan="6" style="text-align:center;">No customers found</td></tr>');
                  $('#cig-customers-pagination').empty();
              }
          },
          error: function() {
              $('#cig-customers-tbody').html('<tr><td colspan="6" style="text-align:center;color:red;">Error loading data</td></tr>');
          }
      });
  }

  function renderCustomerTable(customers) {
      if(!customers || !customers.length) {
          $('#cig-customers-tbody').html('<tr><td colspan="6" style="text-align:center;">No data found</td></tr>');
          return;
      }
      var html = '';
      customers.forEach(function(c) {
          var taxDisplay = escapeHtml(c.tax_id || '—');
          
          html += '<tr class="cig-customer-row" data-customer-id="' + c.id + '" style="cursor:pointer;">';
          html += '<td><strong>' + escapeHtml(c.name) + '</strong></td>';
          html += '<td style="color:#50529d;font-weight:bold;">' + taxDisplay + '</td>';
          html += '<td>' + c.count + '</td>';
          html += '<td>' + formatCurrency(c.revenue) + '</td>';
          html += '<td style="color:#28a745;">' + formatCurrency(c.paid) + '</td>';
          html += '<td style="color:#dc3545;font-weight:bold;">' + formatCurrency(c.due) + '</td>';
          html += '</tr>';
      });
      $('#cig-customers-tbody').html(html);
  }

  function renderCustomerPagination() {
      var $con = $('#cig-customers-pagination');
      $con.empty();
      if(custPagination.total_pages <= 1) return;
      
      var cp = custPagination.current_page;
      var tp = custPagination.total_pages;
      
      var html = '<button class="cig-page-btn cig-cust-page-btn" data-page="' + (cp - 1) + '" ' + (cp <= 1 ? 'disabled' : '') + '>«</button>';
      html += ' <span style="font-size:12px;margin:0 5px;">Page ' + cp + ' of ' + tp + '</span> ';
      html += '<button class="cig-page-btn cig-cust-page-btn" data-page="' + (cp + 1) + '" ' + (cp >= tp ? 'disabled' : '') + '>»</button>';
      
      $con.html(html);
  }

  function showCustomerDetail(custId) {
      $('#cig-customer-list-panel').slideUp();
      $('#cig-customer-detail-panel').slideDown();
      $('#cig-cust-invoices-tbody').html('<tr class="loading-row"><td colspan="7"><div class="cig-loading-spinner"><div class="spinner"></div></div></td></tr>');
      
      $.ajax({
          url: cigStats.ajax_url,
          method: 'POST',
          dataType: 'json',
          data: {
              action: 'cig_get_customer_invoices_details',
              nonce: cigStats.nonce,
              customer_id: custId,
              date_from: custFilters.dateFrom,
              date_to: custFilters.dateTo
          },
          success: function(res) {
              if(res.success && res.data) {
                  $('#cig-customer-detail-title').text(res.data.customer_name + ' - Invoices');
                  renderCustomerInvoices(res.data.invoices);
              } else {
                  $('#cig-cust-invoices-tbody').html('<tr><td colspan="7">No invoices found</td></tr>');
              }
          },
          error: function() {
              $('#cig-cust-invoices-tbody').html('<tr><td colspan="7">Error loading details</td></tr>');
          }
      });
  }

  function renderCustomerInvoices(invoices) {
      if(!invoices || !invoices.length) {
          $('#cig-cust-invoices-tbody').html('<tr><td colspan="7">No invoices found</td></tr>');
          return;
      }
      var html = '';
      invoices.forEach(function(inv) {
          var statusBadge = '';
          if(inv.status === 'Paid') {
              statusBadge = '<span class="cig-badge badge-sold">Paid</span>';
          } else {
              statusBadge = '<span class="cig-badge badge-canceled">Unpaid</span>';
          }

          html += '<tr>';
          html += '<td><a href="' + inv.view_url + '" target="_blank" style="font-weight:bold;color:#50529d;">' + inv.number + '</a></td>';
          html += '<td>' + inv.date + '</td>';
          html += '<td>' + formatCurrency(inv.total) + '</td>';
          html += '<td style="color:#28a745;">' + formatCurrency(inv.paid) + '</td>';
          html += '<td style="color:#dc3545;">' + formatCurrency(inv.due) + '</td>';
          html += '<td>' + statusBadge + '</td>';
          html += '<td><a href="' + inv.view_url + '" class="cig-btn-sm cig-btn-view" target="_blank">View</a></td>';
          html += '</tr>';
      });
      $('#cig-cust-invoices-tbody').html(html);
  }

  // --- PRODUCT INSIGHT LOGIC ---
  function initProductSearch() {
      $('#cig-product-insight-search').autocomplete({
          minLength: 2,
          source: function(request, response) {
              $.ajax({
                  url: cigStats.ajax_url,
                  method: 'POST',
                  dataType: 'json',
                  data: {
                      action: 'cig_search_products', 
                      nonce: cigStats.nonce,
                      term: request.term
                  },
                  success: function(data) { response(data || []); },
                  error: function() { response([]); }
              });
          },
          select: function(event, ui) {
              currentPiFilter.productId = ui.item.id;
              $('#cig-product-filters').slideDown();
              loadProductInsight(ui.item.id);
          }
      });
  }

  function loadProductInsight(productId) {
      $('#cig-product-insight-results').hide();
      $('#cig-pi-loading').show();

      $.ajax({
          url: cigStats.ajax_url,
          method: 'POST',
          dataType: 'json',
          data: {
              action: 'cig_get_product_insight',
              nonce: cigStats.nonce,
              product_id: productId,
              date_from: currentPiFilter.dateFrom,
              date_to: currentPiFilter.dateTo
          },
          success: function(res) {
              $('#cig-pi-loading').hide();
              if(res.success && res.data) {
                  renderProductInsight(res.data);
                  $('#cig-product-insight-results').fadeIn();
              } else {
                  alert('Error loading data');
              }
          },
          error: function() {
              $('#cig-pi-loading').hide();
              alert('Connection error');
          }
      });
  }

  function renderProductInsight(data) {
      $('#cig-pi-img').attr('src', data.image);
      $('#cig-pi-title').text(data.name);
      $('#cig-pi-sku').text(data.sku);
      $('#cig-pi-price').text(formatCurrency(data.current_price));

      $('#cig-pi-sold').text(formatNumber(data.total_sold));
      $('#cig-pi-revenue').text(formatCurrency(data.total_revenue));
      $('#cig-pi-stock').text(formatNumber(data.stock_qty));
      $('#cig-pi-reserved').text(formatNumber(data.total_reserved));

      var payHtml = '';
      if(data.payment_breakdown && Object.keys(data.payment_breakdown).length) {
          Object.keys(data.payment_breakdown).forEach(function(k){
              var label = cigStats.payment_types[k] || k;
              payHtml += '<tr><td>' + label + '</td><td><strong>' + formatCurrency(data.payment_breakdown[k]) + '</strong></td></tr>';
          });
      } else {
          payHtml = '<tr><td colspan="2" style="color:#999;">No sales in this period</td></tr>';
      }
      $('#cig-pi-payments-tbody').html(payHtml);

      var statHtml = '';
      statHtml += '<tr><td><span class="cig-badge badge-canceled" style="background:#f8d7da;color:#721c24;">Fictive</span></td><td><strong>' + formatNumber(data.total_fictive) + '</strong></td><td>Items in fictive invoices</td></tr>';
      statHtml += '<tr><td><span class="cig-badge badge-canceled">Canceled</span></td><td><strong>' + formatNumber(data.total_canceled) + '</strong></td><td>Items in canceled invoices</td></tr>';
      $('#cig-pi-statuses-tbody').html(statHtml);

      var invHtml = '';
      if(data.product_invoices && data.product_invoices.length > 0) {
          data.product_invoices.forEach(function(inv){
              var typeBadge = (inv.type === 'fictive') 
                  ? '<span class="cig-badge badge-canceled" style="background:#f8d7da;color:#721c24;">Fictive</span>' 
                  : '<span class="cig-badge badge-sold" style="background:#d4edda;color:#155724;">Active</span>';
              
              var stClass = 'badge-sold';
              if(inv.status === 'reserved') stClass = 'badge-reserved';
              if(inv.status === 'canceled') stClass = 'badge-canceled';
              var statusSpan = '<span class="cig-badge '+stClass+'">' + capitalize(inv.status) + '</span>';

              invHtml += '<tr>';
              invHtml += '<td>' + inv.date + '</td>';
              invHtml += '<td><a href="' + inv.edit_url + '" target="_blank" style="font-weight:bold;color:#50529d;">' + escapeHtml(inv.number) + '</a></td>';
              invHtml += '<td>' + escapeHtml(inv.customer) + '</td>';
              invHtml += '<td>' + typeBadge + '</td>';
              invHtml += '<td>' + statusSpan + '</td>';
              invHtml += '<td><strong>' + formatNumber(inv.qty) + '</strong></td>';
              invHtml += '<td>' + formatCurrency(inv.price) + '</td>';
              invHtml += '<td>' + formatCurrency(inv.total) + '</td>';
              invHtml += '<td>' + escapeHtml(inv.author) + '</td>';
              invHtml += '</tr>';
          });
      } else {
          invHtml = '<tr><td colspan="9" style="text-align:center;color:#999;padding:20px;">No invoices found for this period</td></tr>';
      }
      $('#cig-pi-invoices-tbody').html(invHtml);
  }

  function capitalize(s) {
      return s && s[0].toUpperCase() + s.slice(1);
  }

  // Utility functions
  function formatNumber(num) { return parseFloat(num || 0).toLocaleString('en-US'); }
  function formatCurrency(amount) { return parseFloat(amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₾'; }
  function formatDateTime(dateString) { if (!dateString) return '-'; var date = new Date(dateString.replace(' ', 'T')); return date.toLocaleString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }); }
  function formatDateShort(dateString) { if (!dateString) return '-'; var date = new Date(dateString.replace(' ', 'T')); return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }); }
  function formatDate(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0'); }
  function escapeHtml(text) { var map = { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }; return String(text || '').replace(/[&<>"']/g, function(m){ return map[m]; }); }

});