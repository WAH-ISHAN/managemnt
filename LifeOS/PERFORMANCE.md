# Student Life Management — resource and load test

Measured on 24 October 2026 using the actual Windows desktop application and local SQLite database. The LifeOS name is the brand of a student life management application.

## Test machine and method

- Intel Core i5-1155G7, 8 logical processors, 7,894 MiB usable physical RAM.
- Includes the LifeOS launcher, PHP, WebView2 and their child processes (up to 9 processes).
- Sampled every 250 ms using psutil. CPU percentages are normalized to the whole machine: 100% means all 8 logical processors are occupied.
- Used a disposable database with 4,361 synthetic rows: income, allocations, expenses, 24 assets with transactions/metrics/goals, modules, assignments, exams, tasks, lessons and reviews. No personal records were tested or modified.
- Measured system baseline, desktop startup, 15 seconds idle, navigation through six modules, concurrent HTTP bursts, 40 concurrent income writes and 8 seconds recovery. The final measured run ran without builds or regression suites in parallel.
- Raw samples and timings: `performance-results.json`. Test implementation: `tests/performance.py` and `tests/seed_load.php`.

## Application resource use

| Activity | Mean CPU | Mean working set | Mean private commit | Peak working set |
| --- | ---: | ---: | ---: | ---: |
| Idle | 0.27% | 448.69 MiB | 206.47 MiB | 453.28 MiB |
| Navigate modules | 0.80% | 509.57 MiB | 259.62 MiB | 541.00 MiB |
| 1 client reading | 3.81% | 535.34 MiB | 282.57 MiB | 535.57 MiB |
| 4 clients reading | 6.74% | 540.76 MiB | 283.32 MiB | 544.73 MiB |
| 8 clients reading | 7.40% | 531.05 MiB | 269.50 MiB | 544.47 MiB |
| 4 clients writing | 10.69% | 483.02 MiB | 219.71 MiB | 484.71 MiB |
| Recovery | 0.34% | 483.41 MiB | 219.91 MiB | 483.94 MiB |

Peak private commit was 288.96 MiB. Startup reached the idle stage in 1.57 seconds, with a brief sampled CPU peak of 49.94%. The highest sampled CPU during write load was 16.42%.

Working set is the sum of resident memory across application processes; shared pages can be counted more than once. Private commit is process-private committed memory and can include paged memory. These are different measures and should not be treated as interchangeable RAM totals.

## Response times and concurrent load

| Workload | Operations | Concurrent clients | Median | 95th percentile | Maximum | Throughput | Failures |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Read module pages | 32 | 1 | 47.38 ms | 63.84 ms | 65.03 ms | 20.21/s | 0 |
| Read module pages | 64 | 4 | 112.74 ms | 159.83 ms | 202.33 ms | 33.54/s | 0 |
| Read module pages | 96 | 8 | 221.59 ms | 404.91 ms | 467.34 ms | 33.41/s | 0 |
| Save income | 40 | 4 | 99.13 ms | 128.75 ms | 150.24 ms | 22.36/s | 0 |

Reads rotate through Overview, Money, Investments, an asset detail page, University, Lessons, Review and Settings. HTTP timings include response transfer, but do not measure browser paint. Desktop navigation was exercised separately through htmx in WebView2.

Write latency measures POST plus its redirect response. Write throughput includes preparing separate sessions and CSRF tokens. Each write saved Rs. 100.01; all 40 income rows and their allocation totals were verified. SQLite integrity returned `ok`, no application errors were logged, and PHP exited after closing the window.

## Whole-system load

| Stage | Mean system CPU | Used physical RAM | Available physical RAM |
| --- | ---: | ---: | ---: |
| Before opening LifeOS | 2.44% | 5,367.40 MiB | 2,526.70 MiB |
| Application idle | 5.04% | 5,592.93 MiB | 2,301.21 MiB |
| Navigate modules | 4.79% | 5,639.25 MiB | 2,254.89 MiB |
| 8-client read burst | 30.25% | 5,669.48 MiB | 2,224.66 MiB |
| 4-client write burst | 33.85% | 5,629.79 MiB | 2,264.34 MiB |
| Recovery | 5.86% | 5,629.54 MiB | 2,264.60 MiB |

System measurements include Windows, other applications and the test client. They are not attributable entirely to LifeOS. More than 2 GiB of physical memory remained available during the measured load stages.

## Assessment

The measured run stayed responsive and left CPU and memory headroom on this 8 GB laptop. CPU returned close to idle after the burst. The local PHP server processes one request at a time; Windows schedules its CPU use, and concurrent requests queue. Throughput plateaued near 33 reads/second between 4 and 8 clients, while tail latency increased. This is queueing behavior, not evidence of a multi-server load balancer.

No CPU-affinity, process-priority, RAM-limit or Windows settings were changed. This short test does not establish long-term memory stability or worst-case performance for much larger databases. The measurement covers normal desktop navigation and short read/write bursts.

## Reproduce

After building the app, install the test-only dependency and run:

```powershell
python -m pip install psutil
python tests/performance.py --output performance-results.json
```

Run without another LifeOS instance open and avoid builds or other heavy test suites during the measurement. The test creates a temporary database, opens the application in profiling mode, gathers process-tree metrics, then closes it. The installed application does not collect or upload performance data during normal use.
