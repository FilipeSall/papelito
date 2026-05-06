(function () {
  const config = window.papelitoFavorites;

  if (!config || !config.restUrl) {
    return;
  }

  function updateButtonState(button, isFavorite) {
    const nextLabel = isFavorite ? config.removeLabel : config.addLabel;

    button.dataset.isFavorite = isFavorite ? "true" : "false";
    button.setAttribute("aria-pressed", isFavorite ? "true" : "false");
    button.classList.toggle("is-active", isFavorite);

    const label = button.querySelector(".papelito-single-favorite__label");
    if (label) {
      label.textContent = nextLabel;
    }
  }

  function setButtonBusy(button, busy) {
    button.disabled = busy;
    button.classList.toggle("is-busy", busy);

    const label = button.querySelector(".papelito-single-favorite__label");
    if (label && busy) {
      label.textContent = button.dataset.isFavorite === "true"
        ? config.removingLabel
        : config.addingLabel;
    }
  }

  function removeCard(card) {
    if (!card) {
      return;
    }

    const grid = card.parentElement;
    const section = card.closest(".papelito-favorites-account");

    card.remove();

    if (!grid || !section) {
      return;
    }

    const remainingCards = grid.querySelectorAll(".papelito-favorites-card");
    const countElement = section.querySelector(".papelito-favorites-account__header p");

    if (countElement) {
      const count = remainingCards.length;
      countElement.textContent = count === 1 ? "1 produto salvo" : count + " produtos salvos";
    }

    if (remainingCards.length === 0) {
      const emptyState = document.createElement("div");
      emptyState.className = "papelito-favorites-empty";
      emptyState.innerHTML = "<p>" + config.emptyFavoritesLabel + "</p>";
      grid.replaceWith(emptyState);
    }
  }

  async function toggleFavorite(button) {
    const productId = Number(button.dataset.productId || "0");

    if (!Number.isInteger(productId) || productId <= 0) {
      return;
    }

    if (!config.nonce) {
      window.location.href = config.loginUrl;
      return;
    }

    const isFavorite = button.dataset.isFavorite === "true";
    const requestUrl = isFavorite ? config.restUrl.replace(/\/$/, "") + "/" + productId : config.restUrl;
    const method = isFavorite ? "DELETE" : "POST";

    setButtonBusy(button, true);

    try {
      const response = await fetch(requestUrl, {
        method,
        headers: {
          "Content-Type": "application/json",
          "X-WP-Nonce": config.nonce,
        },
        body: isFavorite ? undefined : JSON.stringify({ productId }),
      });

      if (response.status === 401) {
        window.location.href = config.loginUrl;
        return;
      }

      if (!response.ok) {
        throw new Error("Favorites request failed");
      }

      const payload = await response.json();
      const nextFavoriteState = Boolean(payload && payload.isFavorite);

      updateButtonState(button, nextFavoriteState);

      if (!nextFavoriteState) {
        const card = button.closest(".papelito-favorites-card");
        removeCard(card);
      }
    } catch (error) {
      console.error("[papelito:favorites]", error);
      updateButtonState(button, isFavorite);
    } finally {
      setButtonBusy(button, false);
    }
  }

  document.addEventListener("click", function (event) {
    const target = event.target;

    if (!(target instanceof HTMLElement)) {
      return;
    }

    const button = target.closest(".papelito-favorite-toggle");

    if (!(button instanceof HTMLButtonElement)) {
      return;
    }

    event.preventDefault();
    toggleFavorite(button);
  });
})();
