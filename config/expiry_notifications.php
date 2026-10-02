<?php
function getExpiryNotifications($mysqli)
{
    $timezone = new DateTimeZone('Asia/Kuala_Lumpur');
    $today = new DateTime('today', $timezone);

    $query = "SELECT pe.id, pe.batch_no, pe.expiry_date, pe.quantity, i.product_name
        FROM product_expiry pe
        JOIN inventory i ON pe.inventory_id = i.id
        ORDER BY pe.expiry_date ASC, pe.batch_no ASC";

    $result = $mysqli->query($query);
    if (!$result) {
        return [];
    }

    $notifications = [];
    while ($row = $result->fetch_assoc()) {
        $expiryDate = new DateTime($row['expiry_date'], $timezone);
        $expiryDate->setTime(0, 0, 0);

        $daysRemaining = (int)floor(($expiryDate->getTimestamp() - $today->getTimestamp()) / 86400);

        if ($daysRemaining < 0) {
            $severity = 0;
            $message = 'Expired ' . abs($daysRemaining) . ' day' . (abs($daysRemaining) === 1 ? '' : 's') . ' ago';
        } elseif ($daysRemaining === 0) {
            $severity = 1;
            $message = 'Expires today';
        } elseif ($daysRemaining <= 7) {
            $severity = 2;
            $message = 'Expires in ' . $daysRemaining . ' day' . ($daysRemaining === 1 ? '' : 's');
        } else {
            continue;
        }

        $notifications[] = [
            'id' => (int)$row['id'],
            'product_name' => $row['product_name'],
            'batch_no' => $row['batch_no'],
            'expiry_date' => $row['expiry_date'],
            'days_remaining' => $daysRemaining,
            'severity' => $severity,
            'message' => $message,
        ];
    }

    usort($notifications, function ($a, $b) {
        if ($a['severity'] === $b['severity']) {
            return strcmp($a['expiry_date'], $b['expiry_date']);
        }

        return $a['severity'] <=> $b['severity'];
    });

    return $notifications;
}

function renderExpiryNotificationBell($notifications, $targetPage = 'product_expiry.php')
{
    $alertCount = count($notifications);
    $hasAlerts = $alertCount > 0;
    $target = htmlspecialchars($targetPage, ENT_QUOTES, 'UTF-8');

    $itemsMarkup = '';
    if (!$hasAlerts) {
        $itemsMarkup = '<div class="notification-empty">No expiry alerts</div>';
    } else {
        foreach ($notifications as $notification) {
            $productName = htmlspecialchars($notification['product_name'], ENT_QUOTES, 'UTF-8');
            $batchNo = htmlspecialchars($notification['batch_no'], ENT_QUOTES, 'UTF-8');
            $message = htmlspecialchars($notification['message'], ENT_QUOTES, 'UTF-8');
            $indicatorClass = 'indicator-neutral';
            $indicatorLabel = 'Info';
            if ($notification['severity'] === 0) {
                $indicatorClass = 'indicator-danger';
                $indicatorLabel = 'Expired';
            } elseif ($notification['severity'] === 1) {
                $indicatorClass = 'indicator-urgent';
                $indicatorLabel = 'Today';
            } else {
                $indicatorClass = 'indicator-warning';
                $indicatorLabel = 'Soon';
            }

            $itemsMarkup .= '<a href="' . $target . '" class="notification-item">';
            $itemsMarkup .= '<span class="notification-indicator ' . $indicatorClass . '" aria-label="'. $indicatorLabel . '"></span>';
            $itemsMarkup .= '<span class="notification-content">';
            $itemsMarkup .= '<span class="notification-title">' . $productName . '</span>';
            $itemsMarkup .= '<span class="notification-meta">Batch ' . $batchNo . '</span>';
            $itemsMarkup .= '<span class="notification-message">' . $message . '</span>';
            $itemsMarkup .= '</span>';
            $itemsMarkup .= '</a>';
        }
    }

    $countMarkup = $alertCount > 0 ? '<span class="notification-count">' . $alertCount . '</span>' : '';

    return '<div class="notification-wrapper">
        <button class="icon-button notification-bell" type="button" aria-label="Notifications" aria-expanded="false">
            <span class="icon-bell">🔔</span>' . $countMarkup . '
        </button>
        <div class="notification-dropdown" role="menu" aria-label="Expiry notifications">
            <div class="notification-header">
                <div>
                    <div class="notification-header-title">Notifications</div>
                    <div class="notification-header-subtitle">' . $alertCount . ' expiry alert' . ($alertCount === 1 ? '' : 's') . '</div>
                </div>
            </div>
            <div class="notification-list">' . $itemsMarkup . '</div>
            <a href="' . $target . '" class="notification-footer">View All →</a>
        </div>
    </div>
    <script>
    document.addEventListener("DOMContentLoaded", function () {
        const wrappers = document.querySelectorAll(".notification-wrapper");
        if (!wrappers.length) {
            return;
        }

        const closeAllDropdowns = function () {
            wrappers.forEach(function (wrapper) {
                const dropdown = wrapper.querySelector(".notification-dropdown");
                const button = wrapper.querySelector(".notification-bell");
                if (dropdown) {
                    dropdown.classList.remove("show");
                }
                if (button) {
                    button.setAttribute("aria-expanded", "false");
                }
            });
        };

        wrappers.forEach(function (wrapper) {
            const button = wrapper.querySelector(".notification-bell");
            const dropdown = wrapper.querySelector(".notification-dropdown");
            if (!button || !dropdown) {
                return;
            }

            button.addEventListener("click", function (event) {
                event.stopPropagation();
                const isOpen = dropdown.classList.contains("show");
                closeAllDropdowns();
                if (!isOpen) {
                    dropdown.classList.add("show");
                    button.setAttribute("aria-expanded", "true");
                }
            });
        });

        document.addEventListener("click", function (event) {
            if (!event.target.closest(".notification-wrapper")) {
                closeAllDropdowns();
            }
        });

        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape") {
                closeAllDropdowns();
            }
        });
    });
    </script>';
}
