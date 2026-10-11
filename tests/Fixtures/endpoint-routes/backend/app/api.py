from fastapi import APIRouter, FastAPI

app = FastAPI()
router = APIRouter(prefix="/items")


@router.get("/{item_id}")
def show(item_id: int) -> int:
    return item_id


@app.get("/health")
def health() -> str:
    return "ok"


app.include_router(router, prefix="/api/v1")
