/**
 * @provides javelin-behavior-metric-report
 * @requires javelin-behavior
 *           javelin-dom
 *           highcharts-adapters-standalone-framework
 */

JX.behavior('metric-report', function(config) {
  var chart;

  if (!config.y_axis_suffix && config.data_type == 'percent') {
    config.y_axis_suffix = '%';
  }

  chart = new Highcharts.Chart({
    chart: {
      renderTo: 'report-container',
      type: config.type,
      zoomType: 'x'
    },
    title: {
      text: config.title
    },
    xAxis: {
      categories: config.categories,
      title: {
        text: config.x_axis_title
      }
    },
    yAxis: {
      min: 0,
      title: {
        text: config.y_axis_title
      },
      plotLines: config.y_axis_plot_lines,
      labels: {
        formatter: function () {
          return this.value + config.y_axis_suffix;
        }
      }
    },
    legend: {
      labelFormatter: function () {
        var grand_total,
            percentage,
            average,
            total = array_sum(this.yData),
            result = this.name;

        if (config.data_type === 'percent' || config.data_type == 'average') {
          average = total / this.yData.length;

          result += ' - ' + nice_number(average) + config.y_axis_suffix;
        }
        else {
          result += ' - ' + nice_number(total) + config.y_axis_suffix;

          if (config.type === 'column' && config.data_type != 'percent') {
            grand_total = get_grand_total(this.chart);
            percentage = (total / grand_total) * 100;

            if (percentage < 100) {
              result += ' (' + nice_number(percentage) + '%)';
            }
          }
        }

        return result;
      },
      backgroundColor: (Highcharts.theme && Highcharts.theme.background2) || 'white',
      borderColor: '#CCC',
      borderWidth: 1,
      borderRadius: 5,
      shadow: false
    },
    tooltip: {
      formatter: function () {
        var i,
          point,
          percent,
          show_percent = array_count_non_zero(this.points, 'y') > 1 && config.type === 'column' && config.data_type != 'percent',
          total = array_sum_property(this.points, 'y'),
          result = '<span>Time frame: <strong>' + this.points[0].key + '</strong></span><br/>';

        for (i = 0; i < this.points.length; i++) {
          point = this.points[i];

          if (point.y === 0) {
            continue;
          }

          result += '<span style="color:' + point.series.color + '">' + point.series.name + '</span>: ';
          result += '<strong>' + point.y + config.y_axis_suffix + '</strong>';

          if (show_percent) {
            percent = point.y * 100 / total;
            result += ' (<strong>' + nice_number(percent) + '%</strong>)';
          }

          result += '<br/>';
        }

        if (show_percent) {
          result += 'Total: <strong>' + total + '</strong>';
        }

        return result;
      },
      //headerFormat: '<span>Time frame: <strong>{point.key}</strong></span><br/>',
      //pointFormat: '<span style="color:{series.color}">{series.name}</span>: <strong>{point.y}</strong> (<strong>{point.percentage:.1f}%</strong>)<br/>',
      //footerFormat: 'Total: <strong>{point.total}</strong>',
      shared: true
    },
    plotOptions: get_plot_options(config),
    series: config.series
  });

  function get_grand_total(chart) {
    var i, total = 0;

    for (i = 0; i < chart.series.length; i++) {
      total += array_sum(chart.series[i].yData);
    }

    return total;
  }

  function array_sum(array) {
    var i, total = 0;

    for (i = 0; i < array.length; i++) {
      total += array[i];
    }

    return total;
  }

  function array_count_non_zero(array, property_name) {
    var i, total = 0;

    for (i = 0; i < array.length; i++) {
      if (array[i][property_name] === 0) {
        continue;
      }

      total++;
    }

    return total;
  }

  function array_sum_property(array, property_name) {
    var i, total = 0;

    for (i = 0; i < array.length; i++) {
      total += array[i][property_name];
    }

    return total;
  }

  function get_plot_options(config) {
    var plot_options = {},
      default_plot_options = {
        spline: {
          dataLabels: {
            enabled: true,
            formatter: function () {
              return this.y !== 0 ? this.y + config.y_axis_suffix : '';
            }
          }
        },
        column: {
          stacking: config.stacking,
          dataLabels: {
            enabled: true,
            formatter: function () {
              return this.y !== 0 ? this.y + config.y_axis_suffix : '';
            },
            color: (Highcharts.theme && Highcharts.theme.dataLabelsColor) || 'white',
            style: {
              textShadow: '0 0 3px black'
            }
          }
        }
      }
    ;

    var plot_option;

    for (var i = 0; i < config.plot_options.length; i++) {
      plot_option = config.plot_options[i];

      if (default_plot_options[plot_option]) {
        plot_options[plot_option] = default_plot_options[plot_option];
      }
    }

    return plot_options;
  }

  function nice_number(number) {
    return number.toFixed(1).replace(/\.0$/, '');
  }
});
